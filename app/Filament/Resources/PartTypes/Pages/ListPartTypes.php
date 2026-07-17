<?php

namespace App\Filament\Resources\PartTypes\Pages;

use App\Filament\Resources\PartTypes\PartTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartTypes extends ListRecords
{
    protected static string $resource = PartTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
