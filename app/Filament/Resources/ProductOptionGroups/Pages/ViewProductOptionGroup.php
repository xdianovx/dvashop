<?php

namespace App\Filament\Resources\ProductOptionGroups\Pages;

use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProductOptionGroup extends ViewRecord
{
    protected static string $resource = ProductOptionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
