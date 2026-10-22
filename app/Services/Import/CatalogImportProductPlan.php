<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use RuntimeException;

/** Excel assortment identities only: never a CSV, variant, price or authorization plan. */
final class CatalogImportProductPlan
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly CatalogImportExpectedProducts $expectedProducts,
    ) {}

    public function plan(string $xlsxPath, string $source = 'catalog'): array
    {
        $source = CatalogText::normKey($source ?: 'catalog', 'catalog', 60);
        $sha256 = is_file($xlsxPath) ? hash_file('sha256', $xlsxPath) : false;
        if (! is_string($sha256)) {
            throw new RuntimeException('Не удалось прочитать исходный XLSX.');
        }
        $sheet = $this->reader->sheetName($xlsxPath);
        $headers = $this->reader->readMergedDetailHeaders($xlsxPath);
        $skipped = [];
        $rows = (function () use ($xlsxPath, &$skipped): iterable {
            foreach ($this->reader->catalogRows($xlsxPath) as $entry) {
                if ($entry['row'] === null) {
                    $skipped[] = ['row' => $entry['number'], 'reason' => $entry['reason']];

                    continue;
                }
                yield $entry['row'] + ['_row_number' => $entry['number']];
            }
        })();
        $calculation = $this->expectedProducts->collect($rows, $headers, $source, strict: false);
        if (! hash_equals($sha256, (string) hash_file('sha256', $xlsxPath))) {
            throw new RuntimeException('XLSX изменился во время расчёта. Повторите предпросмотр.');
        }
        $expected = $calculation['products'];
        $missing = [];
        $errors = $calculation['identity_errors'];
        $errorCount = $calculation['identity_error_count'];
        $missingTypes = [];
        Product::withTrashed()->where('import_source', $source)->whereNotNull('import_key')
            ->with('partType:id,full_slug')->select(['id', 'part_type_id', 'import_key', 'slug', 'status', 'deleted_at', 'publication_override'])
            ->chunkById(500, function ($products) use (&$expected, &$missing, &$missingTypes, $calculation): void {
                foreach ($products as $product) {
                    $present = isset($expected[$product->import_key]);
                    // Final snapshot publication excludes absent soft-deleted products.
                    if (! $present && $product->trashed()) {
                        continue;
                    }
                    $archived = $product->status->value === 'archived';
                    $manualHide = in_array($product->publication_override, ['archived', 'draft'], true);
                    $state = $product->trashed() ? 'deleted' : ($archived ? 'archived' : 'existing');
                    if ($present) {
                        $action = $manualHide ? 'preserve_manual' : (($archived || $product->trashed()) ? 'reactivate' : 'keep');
                    } else {
                        $action = $archived ? 'keep_archived' : ($product->publication_override === 'draft' ? 'preserve_manual' : 'archive');
                    }
                    $record = [
                        'product_id' => $product->id, 'slug' => $product->slug,
                        'current_status' => $product->status->value, 'state' => $state, 'action' => $action,
                        'restore_deleted' => $present && $product->trashed(),
                        'publication_override' => $product->publication_override,
                    ];
                    if ($present) {
                        $expected[$product->import_key] += $record;
                    } else {
                        $missing[] = ['import_key' => $product->import_key,
                            'part_type_full_slug' => $product->partType?->full_slug] + $record;
                        if (! in_array($product->partType?->full_slug, $calculation['required_part_types'], true)) {
                            $missingTypes[$product->partType?->full_slug ?? '(missing)'] = true;
                        }
                    }
                }
            });
        foreach ($expected as &$record) {
            $record += ['product_id' => null, 'slug' => null, 'current_status' => null,
                'state' => 'new', 'action' => 'create', 'restore_deleted' => false, 'publication_override' => null];
        }
        unset($record);
        $expected = array_values($expected);
        $all = collect([...$expected, ...$missing]);
        $resolvedExpected = collect($expected)->where('state', '!=', 'unresolved');
        $resolvedAll = $all->where('state', '!=', 'unresolved');

        foreach (array_keys($missingTypes) as $path) {
            $errors[] = ['code' => 'type_structure_change', 'part_type_full_slug' => $path,
                'message' => 'Исторический тип отсутствует в колонках Excel; требуется отдельное решение по ассортименту.'];
            $errorCount++;
        }
        if ($skipped !== []) {
            $errors[] = ['code' => 'incomplete_source_rows', 'message' => 'Неполные строки не доказывают исчезновение автомобилей. Автоматическое архивирование не подтверждено.'];
            $errorCount += count($skipped);
        }
        $valid = $errorCount === 0;

        $report = [
            'version' => 1,
            'purpose' => 'excel_assortment_only',
            'valid' => $valid,
            'source' => $source,
            'file' => ['name' => basename($xlsxPath), 'sha256' => $sha256, 'sheet' => $sheet],
            'counts' => [
                'expected' => count($expected), 'new' => collect($expected)->where('state', 'new')->count(),
                'existing' => collect($expected)->where('product_id', '!=', null)->where('state', '!=', 'unresolved')->count(),
                'archived' => $resolvedExpected->where('current_status', 'archived')->count(),
                'reactivate' => $resolvedAll->where('action', 'reactivate')->count(),
                'restore_deleted' => $resolvedAll->where('restore_deleted', true)->count(),
                'missing' => count($missing), 'archive' => $all->where('action', 'archive')->count(),
                'already_archived' => $all->where('action', 'keep_archived')->count(),
                'preserve_manual' => $all->where('action', 'preserve_manual')->count(),
                'unresolved' => collect($expected)->where('state', 'unresolved')->count(),
                'historical_matches' => collect($expected)->where('identity_match', 'historical')->whereNotNull('product_id')->where('state', '!=', 'unresolved')->count(),
                'historical_description_matches' => collect($expected)->where('historical_description_match', true)->where('state', '!=', 'unresolved')->count(),
            ],
            // Include zero-positive columns: real import checks every header type.
            'required_part_types' => $calculation['required_part_types'],
            'expected_products' => $expected,
            'missing_products' => $missing,
            'diagnostics' => [
                'accepted_rows' => $calculation['accepted_rows'], 'skipped_rows' => $skipped,
                'duplicate_vehicle_rows' => $calculation['duplicate_vehicle_rows'],
                'duplicate_positive_cells' => $calculation['duplicate_positive_cells'],
                'errors' => array_slice($errors, 0, 25), 'error_count' => $errorCount,
                'identity_error_count' => $calculation['identity_error_count'],
                'counts_by_code' => $calculation['identity_error_codes'] + [
                    'type_structure_change' => count($missingTypes), 'incomplete_source_rows' => count($skipped),
                ],
                'automatic_archiving_safe' => $valid,
            ],
        ];
        if (! $valid) {
            $report['candidate_counts'] = $report['counts'];
            $report['counts']['new'] = null;
            $report['counts']['archive'] = null;
        }

        return $report;
    }

    /** Execution with explicit exceptions does not claim complete Excel correspondence. */
    public function full(string $xlsxPath, string $source = 'catalog', bool $materialized = false): array
    {
        $result = app(CatalogImportAssortmentPolicy::class)->apply($this->plan($xlsxPath, $source), $xlsxPath, $materialized);
        if (! hash_equals($result['file']['sha256'], (string) hash_file('sha256', $xlsxPath))) {
            throw new RuntimeException('Excel изменился во время расчёта полного импорта.');
        }

        return $result;
    }
}
