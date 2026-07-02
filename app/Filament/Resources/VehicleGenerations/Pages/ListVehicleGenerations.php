<?php

namespace App\Filament\Resources\VehicleGenerations\Pages;

use App\Filament\Resources\VehicleGenerations\VehicleGenerationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVehicleGenerations extends ListRecords
{
    protected static string $resource = VehicleGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
