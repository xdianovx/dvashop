<?php

namespace App\Filament\Resources\VehicleGenerations\Pages;

use App\Filament\Resources\VehicleGenerations\VehicleGenerationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditVehicleGeneration extends EditRecord
{
    protected static string $resource = VehicleGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
