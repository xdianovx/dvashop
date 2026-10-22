<?php

namespace App\Services\Import;

use App\Models\CatalogConfiguration;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Catalog\CatalogVariantDifference;
use App\Support\CatalogText;
use Illuminate\Database\Eloquent\Collection as Models;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Read-only forecast. Virtual negative IDs are local identities, never persistence instructions. */
final class CatalogImportVariantPlan
{
    private const BATCH = 50;

    private const SAMPLE_LIMIT = 25;

    private const EFFECTS = ['products_changed', 'variants_create', 'variants_update', 'variants_reactivate',
        'variants_retire', 'variants_unchanged', 'variant_prices_change', 'product_prices_change',
        'individual_prices_overridden', 'default_changes'];

    public function __construct(
        private readonly CatalogImportConfigurationPlan $projection,
        private readonly CatalogVariantDifference $difference,
    ) {}

    public function plan(string $csvPath, string $scope = 'csv', ?array $productPlan = null, string $source = 'catalog', bool $includeProducts = false): array
    {
        if (! in_array($scope, ['full', 'csv'], true) || ($scope === 'full' && $productPlan === null)) {
            throw new InvalidArgumentException('Нужен scope full/csv; для full требуется план Excel этапа 2А.');
        }
        $source = CatalogText::normKey($scope === 'full' ? $productPlan['source'] : $source, 'catalog', 60);
        $projection = $scope === 'full' ? $this->projection->plan($productPlan, $csvPath) : $this->projection->csv($csvPath, $source);
        $report = [
            'version' => 1, 'purpose' => 'projected_variant_diff', 'valid' => true,
            'source_hashes' => ['csv' => $projection['files']['csv']['sha256'], 'excel' => $projection['files']['excel']['sha256'] ?? null],
            'scope' => $scope, 'source' => $source, 'counts' => $this->counts(), 'by_type' => [],
            'assortment' => $scope === 'full' ? [
                'counts' => $productPlan['counts'],
                'missing_samples' => array_slice($productPlan['missing_products'], 0, self::SAMPLE_LIMIT),
                'missing_truncated' => max(0, count($productPlan['missing_products']) - self::SAMPLE_LIMIT),
            ] : null,
            'diagnostics' => ['counts_by_code' => [], 'samples' => [], 'truncated' => 0],
        ];
        foreach ($projection['diagnostics']['errors'] as $error) {
            $this->diagnostic($report, $error['code'], $error['message'], null, $error['part_type_full_slug'] ?? null);
        }
        if ($scope === 'full' && $source !== 'catalog') {
            $this->diagnostic($report, 'unsupported_full_source', 'Для этого источника ImportProductFactory не синхронизирует CSV-варианты.');
        }
        try {
            app(CatalogConfigurations::class)->assertReady();
        } catch (ValidationException $exception) {
            $this->diagnostic($report, 'missing_schema', $exception->getMessage());

            $report = $this->finish($report, true);
            $report['counts'] = array_fill_keys(array_keys($report['counts']), null);

            return $report;
        }

        $types = PartType::all()->keyBy('full_slug');
        $templates = ProductOptionTemplate::all();
        $history = CatalogConfiguration::with('template')->orderBy('id')->get();
        $historyByType = $history->groupBy('part_type_id');
        $historyById = $history->keyBy('id');
        $groups = ProductOptionGroup::with('values')->get()->keyBy('code');
        $matrix = [];
        $issues = [];
        $virtualId = -1;
        foreach ($projection['matrix'] as $path => $entry) {
            $type = $types->get($path);
            $slug = 'matrix_'.str_replace('/', '_', $path);
            $template = $templates->firstWhere('slug', $slug);
            $templateId = $template?->id ?? $virtualId--;
            $rows = collect();
            if (! $type) {
                $issues[$path] = ['missing_type', 'Тип отсутствует в БД; его создание и связи требуют отдельной проверки.'];
            } elseif ($templates->contains(fn ($t) => $t->part_type_id === $type->id && $t->is_default && $t->slug !== $slug)) {
                $issues[$path] = ['custom_default_template', 'Установка CSV заблокирована пользовательским шаблоном типа.'];
            } elseif ($template && (! $template->is_active || (int) $template->part_type_id !== (int) $type->id)) {
                $issues[$path] = ['invalid_matrix_template', 'Шаблон матрицы отключён или относится к другому типу.'];
            }
            $oldRows = $historyByType->get($type?->id, collect());
            foreach ($entry['configurations'] as $signature => $configuration) {
                $matches = $oldRows->where('signature', $signature);
                if ($matches->count() > 1) {
                    $issues[$path] = ['ambiguous_configuration', 'Несколько исторических конфигураций имеют одну сигнатуру.'];
                }
                foreach ($configuration['options'] as $code => $value) {
                    $group = $groups->get($code);
                    $option = $group?->values->firstWhere('code', $value);
                    if (! $group || ! $group->is_active || ($option && ! $option->is_active)) {
                        $issues[$path] = ['missing_or_disabled_option', 'Группа отсутствует либо группа/значение отключены: '.$code.':'.$value];
                    }
                    // Missing values are created by install(); no ID is needed for this comparison.
                }
                $rows->push(new CatalogConfiguration([
                    'id' => $matches->first()?->id ?? $virtualId--, 'part_type_id' => $type?->id,
                    'product_option_template_id' => $templateId, 'signature' => $signature,
                    'options' => $configuration['options'], 'price' => $configuration['price'],
                ]));
            }
            $matrix[$path] = $rows;
        }
        // Installation only retires rows owned by matrix_* templates. Do not invent their retirement.
        foreach ($history as $row) {
            $path = $types->firstWhere('id', $row->part_type_id)?->full_slug;
            if ($row->is_active && ! str_starts_with((string) $row->template?->slug, 'matrix_')
                && ! ($matrix[$path] ?? collect())->contains('signature', $row->signature)) {
                $issues[$path ?? '(missing)'] = ['unmanaged_configuration', 'Активная конфигурация вне matrix_* не будет отключена установкой CSV.'];
            }
        }
        foreach ($issues as $path => [$code, $message]) {
            $this->diagnostic($report, $code, $message, null, $path);
        }
        $globalInvalid = ! $report['valid'];
        $relations = ['partType', 'optionTemplate', 'variants' => fn ($q) => $q->orderBy('id'), 'variants.optionValues.group'];
        if ($includeProducts) {
            $relations = [...$relations, 'fitments', 'images'];
        }
        $permanent = array_fill_keys(app(CatalogImportProtections::class)->ids(), true);
        $compareProduct = function (Product $product, string $path, ?array $expected = null) use (
            &$report, $matrix, $historyByType, $historyById, $issues, $globalInvalid,
            $permanent,
        ): void {
            $report['by_type'][$path] ??= ['counts' => $this->counts()];
            $this->increment($report, $path, 'products_scanned');
            if (! $product->exists) {
                $this->increment($report, $path, 'products_new');
            }
            $rows = $matrix[$path] ?? collect();
            $slug = $product->optionTemplate?->slug;
            $managedTemplate = $product->product_option_template_id === null || $slug === 'default_auto_part'
                || ($rows->isNotEmpty() ? $slug === 'matrix_'.str_replace('/', '_', $path)
                    : (str_starts_with((string) $slug, 'matrix_') && (int) $product->optionTemplate?->part_type_id === (int) $product->part_type_id));
            $protected = isset($permanent[$product->id]) || ! $managedTemplate || ($product->import_source !== 'catalog' && ! $product->configuration_managed
                && ! str_starts_with((string) $slug, 'matrix_'));
            $staleIdentity = $expected && ($product->id !== ($expected['product_id'] ?? null)
                || ($product->exists && $product->import_source !== $report['source']));
            if ($protected && ! $staleIdentity) {
                $this->increment($report, $path, 'protected_products');
                $this->increment($report, $path, 'variants_unchanged', $product->variants->count());
                $this->diagnostic($report, 'protected_product', 'Сохранён пользовательский шаблон или товар вне управления CSV.', $product, $path, false);

                return;
            }
            $problem = $issues[$path] ?? null;
            if ($product->part_type_id === null || $path === '(missing)') {
                $problem = ['missing_product_type', 'Тип товара отсутствует; область матрицы не определена.'];
            }
            if ($globalInvalid && $problem === null) {
                $problem = ['invalid_input_plan', 'Исходный план или установка матрицы не прошли проверку.'];
            }
            if ($staleIdentity) {
                $problem = ['stale_product_identity', 'Идентичность товара изменилась после расчёта Excel.'];
            }
            $problem ??= $this->variantConflict($product, $historyById);
            if ($problem !== null) {
                $this->increment($report, $path, 'conflicts');
                $this->increment($report, $path, 'products_unresolved');
                $this->diagnostic($report, $problem[0], $problem[1], $product, $path);

                return;
            }
            $canRetire = $product->configuration_managed && $managedTemplate
                && $historyByType->has($product->part_type_id);
            try {
                $diff = $this->difference->compare($product, $rows, $canRetire, true);
            } catch (ValidationException $exception) {
                $this->increment($report, $path, 'conflicts');
                $this->increment($report, $path, 'products_unresolved');
                $this->diagnostic($report, 'configuration_binding_conflict', $exception->getMessage(), $product, $path);

                return;
            }
            foreach ([
                'products_changed' => (int) $diff['changed'], 'variants_create' => $diff['created_variants'],
                'variants_update' => $diff['variants_update'], 'variants_reactivate' => $diff['reactivated_variants'],
                'variants_retire' => $diff['retired_variants'], 'variants_unchanged' => $diff['unchanged_variants'],
                'variant_prices_change' => $diff['prices_to_change'],
                'product_prices_change' => $product->exists ? $diff['product_prices_to_change'] : 0,
                'individual_prices_overridden' => $diff['price_exceptions'], 'default_changes' => $diff['default_changes'],
            ] as $key => $count) {
                $this->increment($report, $path, $key, $count);
            }
        };

        $consume = function (Product $product, string $path, ?array $expected = null) use ($compareProduct, &$report, $includeProducts): void {
            $before = $report['counts'];
            $compareProduct($product, $path, $expected);
            if ($includeProducts) {
                $delta = [];
                foreach ($report['counts'] as $key => $value) {
                    $delta[$key] = $value - $before[$key];
                }
                $report['product_outcomes'][] = ['import_key' => $product->import_key, 'product_id' => $product->id,
                    'counts' => $delta, 'fingerprint' => $product->exists ? CatalogSafePartialState::product($product) : null];
            }
        };
        if ($scope === 'csv') {
            // Exactly the CSV job's scope: no type filter, no withTrashed().
            Product::where('import_source', $source)->whereNotNull('import_key')->with($relations)
                ->chunkById(self::BATCH, function ($products) use ($consume): void {
                    foreach ($products as $product) {
                        $consume($product, $product->partType?->full_slug ?? '(missing)');
                    }
                });
        } else {
            foreach (array_chunk($projection['expected_products'], self::BATCH) as $batch) {
                $existing = Product::withTrashed()->whereIn('import_key', array_column($batch, 'import_key'))
                    ->with($relations)->get()->keyBy('import_key');
                foreach ($batch as $expected) {
                    $path = $expected['part_type_full_slug'];
                    $type = $types->get($path);
                    $product = $existing->get($expected['import_key']);
                    if (! $product) {
                        $product = new Product(['import_source' => $source, 'import_key' => $expected['import_key'],
                            'price' => ($matrix[$path] ?? collect())->min('price'), 'price_mode' => 'inherited']);
                        $product->setRelation('variants', new Models);
                        $product->setRelation('optionTemplate', null);
                    }
                    // Factory assigns the Excel type before synchronizing, including returning deleted products.
                    $product->part_type_id = $type?->id;
                    $consume($product, $path, $expected);
                }
            }
        }
        if (! hash_equals($report['source_hashes']['csv'], (string) hash_file('sha256', $csvPath))) {
            $this->diagnostic($report, 'csv_changed', 'CSV изменился во время расчёта.');
            $globalInvalid = true;
        }

        $report['input_valid'] = ! $globalInvalid;

        return $this->finish($report, $globalInvalid);
    }

    private function variantConflict(Product $product, Collection $history): ?array
    {
        $seen = [];
        foreach ($product->variants as $variant) {
            if ($variant->optionValues->contains(fn ($value) => $value->group === null)
                || $variant->optionValues->groupBy('product_option_group_id')->contains(fn ($values) => $values->count() > 1)) {
                return ['invalid_variant_options', 'Неполные или неоднозначные опции варианта #'.$variant->id];
            }
            $signature = $this->difference->signature($variant);
            if (isset($seen[$signature])) {
                return ['duplicate_signature', 'Варианты #'.$seen[$signature].' и #'.$variant->id.' имеют одну сигнатуру.'];
            }
            $seen[$signature] = $variant->id;
            if ($variant->catalog_configuration_id !== null) {
                $linked = $history->get($variant->catalog_configuration_id);
                if (! $linked || (int) $linked->part_type_id !== (int) $product->part_type_id || $linked->signature !== $signature) {
                    return ['foreign_configuration', 'Вариант #'.$variant->id.' связан с отсутствующей или чужой конфигурацией.'];
                }
            }
        }

        return null;
    }

    private function counts(): array
    {
        return array_fill_keys(['products_scanned', 'products_new', ...self::EFFECTS, 'protected_products', 'conflicts', 'products_unresolved'], 0);
    }

    private function increment(array &$report, string $path, string $key, int $count = 1): void
    {
        $report['counts'][$key] += $count;
        $report['by_type'][$path]['counts'][$key] += $count;
    }

    private function diagnostic(array &$report, string $code, string $message, ?Product $product = null, ?string $path = null, bool $error = true): void
    {
        $report['valid'] = $report['valid'] && ! $error;
        $report['diagnostics']['counts_by_code'][$code] = ($report['diagnostics']['counts_by_code'][$code] ?? 0) + 1;
        if (count($report['diagnostics']['samples']) < self::SAMPLE_LIMIT) {
            $report['diagnostics']['samples'][] = ['code' => $code, 'reason' => $message, 'product_id' => $product?->id,
                'import_key' => $product?->import_key, 'part_type_full_slug' => $path, 'severity' => $error ? 'error' : 'protected'];
        } else {
            $report['diagnostics']['truncated']++;
        }
    }

    private function finish(array $report, bool $globalInvalid): array
    {
        if ($globalInvalid || $report['counts']['products_unresolved'] > 0) {
            $report['known_counts'] = $report['counts'];
            foreach (self::EFFECTS as $key) {
                $report['counts'][$key] = null;
            }
        }
        foreach ($report['by_type'] as &$type) {
            if ($globalInvalid || $type['counts']['products_unresolved'] > 0) {
                $type['known_counts'] = $type['counts'];
                foreach (self::EFFECTS as $key) {
                    $type['counts'][$key] = null;
                }
            }
        }
        unset($type);
        ksort($report['by_type']);
        ksort($report['diagnostics']['counts_by_code']);

        return $report;
    }
}
