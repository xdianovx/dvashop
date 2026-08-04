<?php

namespace App\Filament\Resources\VehicleMakes\Pages;

use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Models\VehicleMake;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVehicleMake extends CreateRecord
{
    protected static string $resource = VehicleMakeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CatalogStructureAdminService::class)->saveVehicleMake(new VehicleMake, $data);
    }
}
