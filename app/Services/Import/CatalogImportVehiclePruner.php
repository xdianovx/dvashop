<?php

namespace App\Services\Import;

use App\Enums\ProductStatus;
use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\ImportLogger;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;

final class CatalogImportVehiclePruner
{
    public function __construct(private readonly ImportLogger $logger) {}

    public function prune(ImportRun $run): void
    {
        $source = CatalogText::normKey($run->type ?: 'catalog', 'catalog', 60);
        $keepIds = array_values(array_unique([...app(CatalogImportProtections::class)->ids(),
            ...array_keys($run->prepared_rows['full_scope']['preserve'] ?? []),
            ...($run->import_mode === 'full' ? DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->whereNotNull('product_id')->pluck('product_id')->all() : [])]));
        $held = DB::table('product_fitments')->whereIn('product_id', $keepIds)->select('vehicle_generation_id');
        // Unmarked legacy vehicles belong to this source only when their products prove it.
        // Unknown/manual vehicles and vehicles used by other active products are preserved.
        $missing = VehicleGeneration::query()
            ->whereNotIn('id', clone $held)
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
            ->whereNotIn('id', DB::table('vehicle_generations')->whereIn('id', clone $held)->select('vehicle_model_id'))
            ->whereDoesntHave('generations', fn ($q) => $q->where('is_active', true))
            ->get(['id', 'vehicle_make_id']);
        VehicleModel::query()->whereKey($models->modelKeys())->update(['is_active' => false]);
        $makes = VehicleMake::query()->whereKey($models->pluck('vehicle_make_id')->unique())
            ->whereNotIn('id', DB::table('vehicle_models')->whereIn('id', DB::table('vehicle_generations')->whereIn('id', clone $held)->select('vehicle_model_id'))->select('vehicle_make_id'))
            ->whereDoesntHave('models', fn ($q) => $q->where('is_active', true));
        $makeCount = $makes->update(['is_active' => false]);

        $this->logger->info($run, 'Отсутствующие в файле автомобили убраны из активного каталога', [
            'generations' => $missing->count(), 'models' => $models->count(), 'makes' => $makeCount,
        ]);
    }
}
