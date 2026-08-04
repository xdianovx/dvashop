<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductCategory extends CreateRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CatalogStructureAdminService::class)->saveCategory(new ProductCategory, $data);
    }
}
