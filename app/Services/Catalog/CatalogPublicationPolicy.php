<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class CatalogPublicationPolicy
{
    public function present(Product $product): bool
    {
        return $product->import_source === null || ($product->import_present === null
            ? $product->status->value === 'active' : (bool) $product->import_present);
    }

    public function status(bool $present, ?string $override): string
    {
        return match ($override) {
            'draft' => 'draft', 'archived' => 'archived',
            default => $present ? 'active' : 'archived',
        };
    }

    public function publishSnapshot(int $runId, string $source): void
    {
        if (DB::table('import_runs')->where('id', $runId)->value('import_mode') === 'safe_partial') {
            throw new \RuntimeException('Массовая публикация safe_partial запрещена.');
        }
        $items = DB::table('catalog_import_snapshot_items')->where('import_run_id', $runId)->select('product_id');
        $products = DB::table('products')->where('import_source', $source)->whereNotNull('import_key')->whereNull('deleted_at');
        if (app(CatalogImportProtections::class)->ready()) {
            $products->whereNotIn('id', app(CatalogImportProtections::class)->ids());
        }
        $prepared = DB::table('import_runs')->where('id', $runId)->value('prepared_rows');
        $scope = json_decode($prepared ?? '{}', true)['full_scope'] ?? null;
        if ($scope !== null) {
            $products->whereNotIn('id', array_keys($scope['preserve']));
            // Presence of absent rows is changed only by the separately proved archive.
            $products->whereIn('id', $items);
        }
        (clone $products)->whereIn('id', $items)->update(['import_present' => true]);
        (clone $products)->whereNotIn('id', $items)->update(['import_present' => false]);
        // Excel controls assortment presence, but explicit manual draft/archive holds
        // persist until an administrator removes them. Clear only legacy non-hold overrides.
        (clone $products)->update([
            'status' => DB::raw("CASE WHEN publication_override = 'draft' THEN 'draft' "
                ."WHEN publication_override = 'archived' THEN 'archived' "
                ."WHEN import_present = 1 THEN 'active' ELSE 'archived' END"),
            'publication_override' => DB::raw("CASE WHEN publication_override IN ('draft', 'archived') "
                .'THEN publication_override ELSE NULL END'),
        ]);
    }
}
