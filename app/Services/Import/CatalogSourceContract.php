<?php

namespace App\Services\Import;

use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\SpreadsheetReader;
use Illuminate\Validation\ValidationException;

/** The preflight is read-only. A shared CSV group may serve several declared catalog paths. */
final class CatalogSourceContract
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ImportPartTypeResolver $partTypes,
        private readonly CatalogConfigurationSource $configurations,
    ) {}

    /** @return array{columns:int,source_rows:int,groups:int,matched_paths:array<int,string>} */
    public function validate(string $xlsxPath, string $csvPath): array
    {
        $headers = $this->reader->readMergedDetailHeaders($xlsxPath);
        if ($headers === []) {
            throw ValidationException::withMessages(['file' => 'В Excel отсутствуют товарные колонки.']);
        }
        $plan = app(CatalogImportProductPlan::class)->full($xlsxPath);
        if (! $plan['execution_allowed']) {
            throw ValidationException::withMessages(['file' => 'Идентичность/ассортимент Excel не подтверждены: '.($plan['diagnostics']['errors'][0]['message'] ?? 'неразрешённые строки').'.']);
        }
        $paths = [];
        foreach ($headers as $index => $header) {
            $paths[$this->partTypes->structuralPath($header)] =
                CatalogImportCell::column((int) $index).': '.($header['full_detail_title'] ?? $header['title'] ?? '');
        }

        return $this->validatePaths($paths, $csvPath) + ['assortment' => $plan];
    }

    /** @param array<string,string> $paths paths of the last successful Excel snapshot */
    public function validatePaths(array $paths, string $csvPath): array
    {
        if ($paths === []) {
            throw ValidationException::withMessages(['configuration' => 'Нет подтверждённых товарных колонок Excel. Сначала выполните полный импорт.']);
        }
        $csvRows = $this->configurations->read($csvPath);
        $targets = [];
        $groups = [];
        foreach ($csvRows as $row) {
            $groups[$row['group']] ??= [];
            foreach (CatalogConfigurationSource::targetPaths($row) as $path) {
                $targets[$path] = true;
                $groups[$row['group']][$path] = true;
            }
        }

        $missing = [];
        foreach ($paths as $path => $label) {
            if (! isset($targets[$path])) {
                $missing[] = $label.' ('.$path.')';
            }
        }
        // A shared parent path is valid when its actual child columns are in the workbook.
        $orphaned = [];
        foreach ($groups as $label => $pathsForGroup) {
            $represented = false;
            foreach ($pathsForGroup as $target => $_) {
                foreach ($paths as $headerPath => $_) {
                    if ($headerPath === $target || str_starts_with($headerPath, $target.'/')) {
                        $represented = true;
                        break 2;
                    }
                }
            }
            if (! $represented) {
                $orphaned[] = $label;
            }
        }
        if ($missing !== [] || $orphaned !== []) {
            throw ValidationException::withMessages(['configuration' => implode(' ', array_filter([
                $missing === [] ? '' : 'Excel содержит детали без CSV-конфигураций: '.implode('; ', $missing).'.',
                $orphaned === [] ? '' : 'CSV содержит группы без товарных колонок Excel: '.implode(', ', $orphaned).'.',
            ]))]);
        }

        return ['columns' => count($paths), 'source_rows' => count($csvRows), 'groups' => count($groups), 'matched_paths' => array_keys($paths)];
    }
}
