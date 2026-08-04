<?php

namespace App\Filament\Resources\ProductOptionTemplates\Pages;

use App\Filament\Resources\ProductOptionTemplates\ProductOptionTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductOptionTemplates extends ListRecords
{
    protected static string $resource = ProductOptionTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
