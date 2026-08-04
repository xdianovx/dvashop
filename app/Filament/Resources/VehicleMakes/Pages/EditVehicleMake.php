<?php

namespace App\Filament\Resources\VehicleMakes\Pages;

use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Models\VehicleMake;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVehicleMake extends EditRecord
{
    protected static string $resource = VehicleMakeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VehicleMake $record */
        return app(CatalogStructureAdminService::class)->saveVehicleMake($record, $data);
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
