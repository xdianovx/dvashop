<?php

namespace App\Observers;

use App\Jobs\RefreshCatalogSearchDependencies;
use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\CatalogSearchSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CatalogSearchDependencyObserver
{
    public function saved(Model $model): void
    {
        $fields = match ($model::class) {
            VehicleMake::class => ['title', 'search_aliases', 'position', 'is_active'],
            VehicleModel::class => ['title', 'search_aliases', 'vehicle_make_id', 'position', 'is_active'],
            VehicleGeneration::class => ['title', 'body', 'years_label', 'vehicle_model_id', 'is_active'],
            ProductVariant::class => ['sku', 'is_active', 'product_id'],
            ProductOptionValue::class => ['is_active', 'product_option_group_id'],
            ProductOptionGroup::class => ['is_active'],
            ProductFitment::class => ['product_id', 'vehicle_generation_id'],
        };
        if ($model->wasRecentlyCreated || $model->wasChanged($fields)) {
            $this->refresh($model);
        }
    }

    public function deleted(Model $model): void
    {
        $this->refresh($model);
    }

    public function restored(Model $model): void
    {
        $this->refresh($model);
    }

    private function refresh(Model $model): void
    {
        if (! CatalogSearchSync::enabled()) {
            return;
        }
        $product = $model instanceof ProductVariant || $model instanceof ProductFitment;
        $source = $product ? Product::class : $model::class;
        $ids = $product ? array_values(array_unique(array_filter([(int) $model->product_id, (int) $model->getRawOriginal('product_id')]))) : [(int) $model->getKey()];
        // Capture scalar identity now. Rollback discards the callback and its work.
        DB::afterCommit(fn () => RefreshCatalogSearchDependencies::dispatch($source, $ids));
    }
}
