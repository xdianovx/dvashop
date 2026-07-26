<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Media\ProductGalleryService;
use Filament\Notifications\Notification;

trait HandlesProductGalleryUploads
{
    /** @var array<int, string> */
    private array $pendingGalleryUploads = [];

    /** @var array{sku: mixed, price: mixed, old_price: mixed, stock_quantity: mixed, stock_status: mixed} */
    private array $defaultVariantData = [];

    private bool $usesCompactDefaultVariantData = false;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function hydrateDefaultVariantData(array $data): array
    {
        /** @var Product $product */
        $product = $this->record;
        $variant = $product->defaultVariant()->first();

        if (! $variant instanceof ProductVariant) {
            $data['default_stock_quantity'] = null;

            return $data;
        }

        $data['sku'] = $variant->sku ?: ($data['sku'] ?? null);
        $data['price'] = $variant->price;
        $data['old_price'] = $variant->old_price;
        $data['default_stock_quantity'] = $variant->stock_quantity;
        $data['stock_status'] = $variant->stock_status;

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function prepareProductData(array $data): array
    {
        $uploads = $data['gallery_uploads'] ?? [];
        unset($data['gallery_uploads']);

        $this->usesCompactDefaultVariantData = filled($data['price'] ?? null);
        $this->defaultVariantData = [
            'sku' => $data['sku'] ?? null,
            'price' => $data['price'] ?? 0,
            'old_price' => $data['old_price'] ?? null,
            'stock_quantity' => $data['default_stock_quantity'] ?? null,
            'stock_status' => $data['stock_status'] ?? null,
        ];
        unset($data['default_stock_quantity']);

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
        $this->syncDefaultVariant();
        $this->persistPendingGalleryUploads();
    }

    private function syncDefaultVariant(): void
    {
        /** @var Product $product */
        $product = $this->record->refresh();

        /** @var ProductVariant|null $variant */
        $variant = $product->variants()->where('is_default', true)->first()
            ?? $product->variants()->orderBy('id')->first();

        if (! $variant instanceof ProductVariant) {
            $variant = new ProductVariant([
                ...$this->defaultVariantData,
                'product_id' => $product->getKey(),
                'title' => 'Основной',
                'is_active' => true,
            ]);
        } elseif ($this->usesCompactDefaultVariantData) {
            $variant->forceFill($this->defaultVariantData);
        }

        $variant->forceFill([
            'product_id' => $product->getKey(),
            'is_default' => true,
        ])->save();

        $product->unsetRelation('defaultVariant');
        $product->unsetRelation('variants');
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
