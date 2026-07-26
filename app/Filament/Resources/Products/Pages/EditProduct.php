<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Pages\Concerns\HandlesProductGalleryUploads;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use HandlesProductGalleryUploads;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepareProductData($data);
    }

    protected function afterSave(): void
    {
        $this->finishProductSave();
    }

    public function refreshProductGallery(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
