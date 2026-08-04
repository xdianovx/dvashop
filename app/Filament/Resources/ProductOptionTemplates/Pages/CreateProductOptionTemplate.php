<?php

namespace App\Filament\Resources\ProductOptionTemplates\Pages;

use App\Filament\Resources\ProductOptionTemplates\ProductOptionTemplateResource;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductOptionTemplate extends CreateRecord
{
    protected static string $resource = ProductOptionTemplateResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        $items = array_values($data['template_items'] ?? []);
        unset($data['template_items']);
        /** @var User $actor */
        $actor = auth()->user();

        return app(ProductOptionAdminService::class)->createTemplate($actor, $data, $items);
    }
}
