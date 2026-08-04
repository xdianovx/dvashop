<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Pages\Concerns\HandlesProductGalleryUploads;
use App\Filament\Resources\Products\Pages\Concerns\HandlesProductOptionValues;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use HandlesProductGalleryUploads;
    use HandlesProductOptionValues;

    protected static string $resource = ProductResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->prepareProductData($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(ProductAdminService::class)->save(new Product, $data);
    }

    protected function afterCreate(): void
    {
        $this->finishProductOptionSave();
        $this->finishProductSave();
    }
}
