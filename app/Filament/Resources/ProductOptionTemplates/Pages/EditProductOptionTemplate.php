<?php

namespace App\Filament\Resources\ProductOptionTemplates\Pages;

use App\Filament\Resources\ProductOptionTemplates\ProductOptionTemplateResource;
use App\Models\ProductOptionTemplate;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductOptionTemplate extends EditRecord
{
    protected static string $resource = ProductOptionTemplateResource::class;

    protected ?bool $hasDatabaseTransactions = true;

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

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $items = array_values($data['template_items'] ?? []);
        unset($data['template_items']);
        /** @var User $actor */
        $actor = auth()->user();
        /** @var ProductOptionTemplate $record */

        return app(ProductOptionAdminService::class)->updateTemplate($actor, $record, $data, $items);
    }
}
