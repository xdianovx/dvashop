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
            'draft' => 'draft', 'archived' => 'archived', 'force_active' => 'active',
            default => $present ? 'active' : 'archived',
        };
    }

    public function publishSnapshot(int $runId, string $source): void
    {
        $items = DB::table('catalog_import_snapshot_items')->where('import_run_id', $runId)->select('product_id');
        $products = DB::table('products')->where('import_source', $source)->whereNotNull('import_key')->whereNull('deleted_at');
        (clone $products)->whereIn('id', $items)->update(['import_present' => true]);
        (clone $products)->whereNotIn('id', $items)->update(['import_present' => false]);
        (clone $products)->update(['status' => DB::raw("CASE WHEN publication_override = 'draft' THEN 'draft' WHEN publication_override = 'archived' THEN 'archived' WHEN publication_override = 'force_active' THEN 'active' WHEN import_present = 1 THEN 'active' ELSE 'archived' END")]);
    }
}
