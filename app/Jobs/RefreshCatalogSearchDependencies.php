<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\CatalogSearchDocument;
use App\Services\Search\CatalogSearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RefreshCatalogSearchDependencies implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 600;

    public function __construct(public ?string $source = null, public array $ids = [])
    {
        $this->onConnection(config('scout.queue.connection') ?: 'catalog-search');
        $this->onQueue('catalog-search');
        $this->afterCommit();
    }

    public function handle(CatalogSearchIndexer $indexer): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }
        if ($this->source === null) {
            foreach (CatalogSearchDocument::MODELS as $class) {
                $indexer->refresh($class::query());
            }

            return;
        }
        if ($this->source === Product::class) {
            $indexer->refresh(Product::query()->whereKey($this->ids));

            return;
        }
        if (in_array($this->source, [ProductOptionValue::class, ProductOptionGroup::class], true)) {
            $indexer->refresh(Product::query()->whereHas('variants.optionValues', function ($values): void {
                if ($this->source === ProductOptionValue::class) {
                    $values->whereKey($this->ids);
                } else {
                    $values->whereIn('product_option_group_id', $this->ids);
                }
            }));

            return;
        }
        $generations = VehicleGeneration::withTrashed();
        if ($this->source === VehicleMake::class) {
            $models = VehicleModel::withTrashed()->whereIn('vehicle_make_id', $this->ids);
            $indexer->refresh(clone $models);
            $generations->whereIn('vehicle_model_id', $models->select('id'));
            $indexer->refresh(clone $generations);
        } elseif ($this->source === VehicleModel::class) {
            $generations->whereIn('vehicle_model_id', $this->ids);
            $indexer->refresh(clone $generations);
        } else {
            $generations->whereKey($this->ids);
        }
        $indexer->refresh(Product::query()->whereHas('fitments', fn ($q) => $q->whereIn('vehicle_generation_id', $generations->select('id'))));
    }
}
