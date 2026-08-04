<?php

namespace App\Filament\Resources\PartTypes\Pages;

use App\Filament\Resources\PartTypes\PartTypeResource;
use App\Models\PartType;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePartType extends CreateRecord
{
    protected static string $resource = PartTypeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CatalogStructureAdminService::class)->savePartType(new PartType, $data);
    }
}
