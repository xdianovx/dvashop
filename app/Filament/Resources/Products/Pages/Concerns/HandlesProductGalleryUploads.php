<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Enums\ProductType;
use App\Models\Product;
use App\Services\Media\ProductGalleryService;
use Filament\Notifications\Notification;

trait HandlesProductGalleryUploads
{
    /** @var array<int, string> */
    private array $pendingGalleryUploads = [];

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function prepareProductData(array $data): array
    {
        $uploads = $data['gallery_uploads'] ?? [];
        unset($data['gallery_uploads']);

        $this->pendingGalleryUploads = array_values(array_filter(
            is_array($uploads) ? $uploads : [$uploads],
            static fn (mixed $path): bool => is_string($path) && trim($path) !== '',
        ));

        if ($this->isGenericProductType($data['product_type'] ?? null)) {
            $data['part_type_id'] = null;
            unset($data['fitments']);
        }

        return $data;
    }

    protected function finishProductSave(): void
    {
        $this->clearGenericProductRelations();
        $this->persistPendingGalleryUploads();
    }

    protected function persistPendingGalleryUploads(): void
    {
        if ($this->pendingGalleryUploads === []) {
            return;
        }

        /** @var Product $product */
        $product = $this->record;

        $images = app(ProductGalleryService::class)->attachManualImages(
            product: $product,
            paths: $this->pendingGalleryUploads,
            alt: $product->title,
        );

        $this->pendingGalleryUploads = [];

        Notification::make()
            ->success()
            ->title('Изображения загружены')
            ->body('Добавлено изображений: '.$images->count())
            ->send();
    }

    private function clearGenericProductRelations(): void
    {
        /** @var Product $product */
        $product = $this->record->refresh();

        if (! $product->isGeneric()) {
            return;
        }

        if ($product->part_type_id !== null) {
            $product->forceFill(['part_type_id' => null])->saveQuietly();
        }

        $product->fitments()->delete();
        $product->unsetRelation('fitments');
    }

    private function isGenericProductType(mixed $state): bool
    {
        return ($state instanceof ProductType ? $state->value : $state) === ProductType::Generic->value;
    }
}
