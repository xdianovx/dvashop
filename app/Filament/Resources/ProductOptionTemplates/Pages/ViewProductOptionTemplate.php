<?php

namespace App\Filament\Resources\ProductOptionTemplates\Pages;

use App\Filament\Resources\ProductOptionTemplates\ProductOptionTemplateResource;
use App\Models\ProductOptionTemplate;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProductOptionTemplate extends ViewRecord
{
    protected static string $resource = ProductOptionTemplateResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ProductOptionTemplate $template */
        $template = $this->getRecord();
        $data['template_items'] = $template->items()
            ->get(['product_option_group_id', 'product_option_value_id', 'position'])
            ->map->attributesToArray()
            ->all();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
