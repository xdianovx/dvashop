<?php

namespace App\Services\Catalog;

use App\Models\PartType;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only preflight for later production recovery; applying happens through reviewed CatalogActions. */
final class CatalogMatrixAudit
{
    public function inspect(string $csvPath): array
    {
        $matrix = app(CatalogConfigurations::class);
        $matrix->assertReady();
        $source = app(CatalogMatrixPriceHistory::class)->sourceSha256();
        $actual = hash_file('sha256', $csvPath);
        if (! is_string($actual) || ! hash_equals($source, $actual)) {
            throw ValidationException::withMessages(['csv' => 'SHA-256 файла не совпадает с установленной матрицей. Ничего не применять.']);
        }
        $rows = app(CatalogConfigurationSource::class)->read($csvPath);
        $registered = DB::table('catalog_matrix_sources')->where('id', 1)->first();
        if ((int) $registered->source_rows !== count($rows)) {
            throw ValidationException::withMessages(['csv' => 'Количество исходных строк не совпадает с подтверждённой установкой.']);
        }
        $expected = [];
        foreach ($rows as $row) {
            foreach (CatalogConfigurationSource::targetPaths($row) as $path) {
                $expected[$path.':'.$row['signature']] = $row;
            }
        }
        $paths = array_values(array_unique(array_merge(...array_map(
            static fn (array $row): array => CatalogConfigurationSource::targetPaths($row), $rows,
        ))));
        $types = PartType::whereIn('full_slug', $paths)->get()->keyBy('id');
        $ids = $types->modelKeys();
        $found = [];
        foreach (DB::table('catalog_configurations')->whereIn('part_type_id', $ids)->get() as $config) {
            if (! $config->is_active) {
                continue;
            }
            $path = $types->get($config->part_type_id)?->full_slug;
            $key = $path.':'.$config->signature;
            $sourceRow = $expected[$key] ?? null;
            if ($sourceRow === null || $config->price !== $sourceRow['price']
                || CatalogConfigurationSource::signature(json_decode($config->options, true, 512, JSON_THROW_ON_ERROR)) !== $config->signature) {
                throw ValidationException::withMessages(['configuration' => 'Матрица БД отличается от CSV: '.$key]);
            }
            $found[$key] = true;
        }
        foreach ($expected as $key => $row) {
            if (! isset($found[$key])) {
                throw ValidationException::withMessages(['configuration' => 'В БД нет разрешённой активной комбинации: '.$key]);
            }
        }

        $totals = array_fill_keys(['products' , 'changed_products', 'variants_to_create',
            'variants_to_update', 'variants_to_retire', 'prices_to_change', 'product_prices_to_change', 'historical_modes',
            'protected', 'errors'], 0);
        $perType = [];
        $issues = [];
        $matrix->prime($ids);
        foreach ($types as $type) {
            $counts = array_fill_keys(array_keys($totals), 0);
            Product::where('product_type', 'auto_part')->where('import_source', 'catalog')->where('part_type_id', $type->id)
                ->with('optionTemplate', 'variants.optionValues.group')->orderBy('id')->chunkById(100, function ($products) use ($matrix, &$counts, &$issues): void {
                    foreach ($products as $product) {
                        $counts['products']++;
                        try {
                            $changes = $matrix->changes($product);
                            if (! empty($changes['changed'])) { $counts['changed_products']++; }
                            $counts['variants_to_create'] += $changes['created_variants'];
                            $counts['variants_to_update'] += $changes['updated_variants'];
                            $counts['variants_to_retire'] += $changes['retired_variants'];
                            $counts['prices_to_change'] += $changes['prices_to_change'];
                            $counts['product_prices_to_change'] += $changes['product_prices_to_change'];
                            $counts['historical_modes'] += $changes['price_exceptions'];
                            if ($product->product_option_template_id && ! in_array($product->optionTemplate?->slug,
                                ['default_auto_part', $matrix->template($product->part_type_id)?->slug], true)) {
                                $counts['protected']++;
                            }
                        } catch (\Throwable $e) {
                            $counts['errors']++;
                            if (count($issues) < 30) {
                                $issues[] = ['product_id' => $product->id, 'error' => $e->getMessage()];
                            }
                        }
                    }
                });
            if ($counts['products'] > 0) {
                $perType[$type->full_slug] = $counts;
                foreach ($totals as $key => &$value) { $value += $counts[$key]; }
                unset($value);
            }
        }
        return ['source_sha256' => $source, 'source_rows' => count($rows),
            'active_configurations' => count($found), 'counts' => $totals, 'by_type' => $perType,
            'issues' => $issues, 'safe_to_request_approval' => $totals['errors'] === 0 && $totals['protected'] === 0];
    }
}
