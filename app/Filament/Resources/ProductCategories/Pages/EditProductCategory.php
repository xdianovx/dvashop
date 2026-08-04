<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductCategory extends EditRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ProductCategory $record */
        return app(CatalogStructureAdminService::class)->saveCategory($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn ($record) => app(CatalogStructureAdminService::class)->deleteCategory($record)),
            RestoreAction::make()->using(fn ($record) => app(CatalogStructureAdminService::class)->restoreCategory($record)),
            ForceDeleteAction::make(),
        ];
    }
}
