<?php

namespace App\Filament\Resources\VehicleModels\Pages;

use App\Filament\Resources\VehicleModels\VehicleModelResource;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVehicleModel extends EditRecord
{
    protected static string $resource = VehicleModelResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VehicleModel $record */
        return app(CatalogStructureAdminService::class)->saveVehicleModel($record, $data);
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
