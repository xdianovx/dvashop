<?php

namespace App\Filament\Resources\VehicleModels\Pages;

use App\Filament\Resources\VehicleModels\VehicleModelResource;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVehicleModel extends CreateRecord
{
    protected static string $resource = VehicleModelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CatalogStructureAdminService::class)->saveVehicleModel(new VehicleModel, $data);
    }
}
