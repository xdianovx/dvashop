<?php

namespace App\Filament\Resources\VehicleGenerations\Pages;

use App\Filament\Resources\VehicleGenerations\VehicleGenerationResource;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVehicleGeneration extends EditRecord
{
    protected static string $resource = VehicleGenerationResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VehicleGeneration $record */
        return app(CatalogStructureAdminService::class)->saveVehicleGeneration($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn ($record) => app(CatalogStructureAdminService::class)->deleteVehicle($record)),
            RestoreAction::make()->using(fn ($record) => app(CatalogStructureAdminService::class)->restoreVehicle($record)),
            ForceDeleteAction::make(),
        ];
    }
}
