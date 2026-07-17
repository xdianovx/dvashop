<?php

namespace App\Filament\Resources\PartTypes\Pages;

use App\Filament\Resources\PartTypes\PartTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPartType extends EditRecord
{
    protected static string $resource = PartTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
