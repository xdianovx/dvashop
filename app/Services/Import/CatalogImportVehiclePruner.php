<?php

namespace App\Services\Import;

use App\Enums\ProductStatus;
use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\ImportLogger;
use App\Support\CatalogText;

final class CatalogImportVehiclePruner
{
    public function __construct(private readonly ImportLogger $logger) {}

    public function prune(ImportRun $run): void
    {
        $source = CatalogText::normKey($run->type ?: 'catalog', 'catalog', 60);
        // Unmarked legacy vehicles belong to this source only when their products prove it.
        // Unknown/manual vehicles and vehicles used by other active products are preserved.
        $missing = VehicleGeneration::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_import_run_id')->orWhere('last_import_run_id', '!=', $run->getKey()))
            ->where(fn ($q) => $q->where('import_source', $source)
                ->orWhere(fn ($legacy) => $legacy->whereNull('import_source')
                    ->whereHas('products', fn ($products) => $products->where('import_source', $source))))
            ->whereDoesntHave('products', fn ($products) => $products->where('status', ProductStatus::Active->value))
            ->get(['id', 'vehicle_model_id']);

        if ($missing->isEmpty()) {
            return;
        }

        VehicleGeneration::query()->whereKey($missing->modelKeys())->update(['is_active' => false]);
        $models = VehicleModel::query()->whereKey($missing->pluck('vehicle_model_id')->unique())
            ->whereDoesntHave('generations', fn ($q) => $q->where('is_active', true))
            ->get(['id', 'vehicle_make_id']);
        VehicleModel::query()->whereKey($models->modelKeys())->update(['is_active' => false]);
        $makes = VehicleMake::query()->whereKey($models->pluck('vehicle_make_id')->unique())
            ->whereDoesntHave('models', fn ($q) => $q->where('is_active', true));
        $makeCount = $makes->update(['is_active' => false]);

        $this->logger->info($run, 'Отсутствующие в файле автомобили убраны из активного каталога', [
            'generations' => $missing->count(), 'models' => $models->count(), 'makes' => $makeCount,
        ]);
    }
}
