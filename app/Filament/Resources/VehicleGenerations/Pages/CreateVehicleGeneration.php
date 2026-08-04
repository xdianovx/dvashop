<?php

namespace App\Filament\Resources\VehicleGenerations\Pages;

use App\Filament\Resources\VehicleGenerations\VehicleGenerationResource;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVehicleGeneration extends CreateRecord
{
    protected static string $resource = VehicleGenerationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CatalogStructureAdminService::class)->saveVehicleGeneration(new VehicleGeneration, $data);
    }
}
