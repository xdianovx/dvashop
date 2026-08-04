<?php

namespace App\Filament\Resources\PartTypes\Pages;

use App\Filament\Resources\PartTypes\PartTypeResource;
use App\Models\PartType;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPartType extends EditRecord
{
    protected static string $resource = PartTypeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PartType $record */
        return app(CatalogStructureAdminService::class)->savePartType($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn ($record) => app(CatalogStructureAdminService::class)->deletePartType($record)),
            RestoreAction::make()->using(fn ($record) => app(CatalogStructureAdminService::class)->restorePartType($record)),
        ];
    }
}
