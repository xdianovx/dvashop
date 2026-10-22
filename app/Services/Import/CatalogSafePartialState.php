<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Services\Catalog\CatalogImportProtections;
use Illuminate\Support\Facades\DB;

final class CatalogSafePartialState
{
    public static function protections(bool $lock = false): string
    {
        $rows = app(CatalogImportProtections::class)->ready()
            ? DB::table('catalog_import_protections')->orderBy('product_id')
                ->when($lock, fn ($q) => $q->lockForUpdate())->get()->all() : [];

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public static function product(Product $product): string
    {
        $product->loadMissing(['variants.optionValues', 'fitments', 'images']);
        $state = ['product' => $product->getRawOriginal(), 'variants' => [], 'fitments' => [], 'images' => []];
        foreach ($product->variants->sortBy('id') as $v) {
            // Variant differences, default selection and price projection do not read
            // quantity or its write timestamp. Synchronization preserves quantity.
            // Keep stock_status and every price/configuration/identity field sealed.
            $attributes = $v->getRawOriginal();
            unset($attributes['stock_quantity'], $attributes['updated_at']);
            $state['variants'][] = [$attributes, $v->optionValues->sortBy('id')
                ->map(fn ($o) => [$o->id, $o->pivot->getRawOriginal()])->values()->all()];
        }
        foreach (['fitments', 'images'] as $relation) {
            $state[$relation] = $product->$relation->sortBy('id')->map(fn ($r) => $r->getRawOriginal())->values()->all();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public static function universe(bool $lock = false): string
    {
        $state = [];
        foreach (['products' => ['id', 'import_key', 'import_source', 'part_type_id', 'product_option_template_id', 'deleted_at'],
            'product_fitments' => ['id', 'product_id', 'vehicle_generation_id', 'note', 'is_primary'],
            'vehicle_generations' => ['*'], 'vehicle_models' => ['*'], 'vehicle_makes' => ['*']] as $table => $columns) {
            $state[$table] = DB::table($table)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get($columns)->all();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
