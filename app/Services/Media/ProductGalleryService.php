<?php

namespace App\Services\Media;

use App\Models\Product;
use App\Models\ProductImage;
use LogicException;
use RuntimeException;

class ProductGalleryService
{
    public function __construct(
        private readonly DefaultProductImageService $defaultImages,
    ) {}

    public function attachManualImage(Product $product, string $path, ?string $alt = null, ?bool $makeMain = null): ProductImage
    {
        $product->loadMissing('defaultVariant');

        $hasMain = $this->productHasMainImage($product);
        $makeMain ??= ! $hasMain;

        return ProductImage::query()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $product->defaultVariant?->getKey(),
            'disk' => 'public',
            'path' => $path,
            'source_type' => ProductImage::SOURCE_MANUAL,
            'is_default' => false,
            'is_visible' => true,
            'is_main' => $makeMain,
            'position' => $this->nextPosition($product),
            'alt' => $alt ?: $product->title,
        ])->refresh();
    }

    public function ensureDefaultImage(Product $product, bool $makeMain = false): ?ProductImage
    {
        $product->loadMissing(['partType', 'category', 'defaultVariant']);

        $default = $this->defaultImages->forProduct($product);

        if ($default === null) {
            return null;
        }

        /** @var ProductImage|null $image */
        $image = ProductImage::query()
            ->where('product_id', $product->getKey())
            ->where('source_type', ProductImage::SOURCE_DEFAULT)
            ->where('is_default', true)
            ->where('path', $default['path'])
            ->first();

        $hasMain = $this->productHasMainImage($product);

        if ($image instanceof ProductImage) {
            $image->forceFill([
                'product_variant_id' => $image->product_variant_id ?: $product->defaultVariant?->getKey(),
                'disk' => DefaultProductImageService::DISK,
                'path' => $default['path'],
                'source_type' => ProductImage::SOURCE_DEFAULT,
                'is_default' => true,
                'is_visible' => true,
                'is_main' => $makeMain || (! $hasMain && ! $image->is_main),
                'alt' => $image->alt ?: $product->title,
            ])->save();

            return $image->refresh();
        }

        return ProductImage::query()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $product->defaultVariant?->getKey(),
            'disk' => DefaultProductImageService::DISK,
            'path' => $default['path'],
            'source_url' => null,
            'source_type' => ProductImage::SOURCE_DEFAULT,
            'is_default' => true,
            'is_visible' => true,
            'is_main' => $makeMain || ! $hasMain,
            'position' => $this->nextPosition($product),
            'alt' => $product->title,
        ])->refresh();
    }

    public function makeDefaultMain(Product $product): ProductImage
    {
        $image = $this->ensureDefaultImage($product, true);

        if (! $image instanceof ProductImage) {
            throw new RuntimeException('Для товара не найдено дефолтное изображение.');
        }

        return $this->makeMain($image);
    }

    public function resetToDefault(Product $product): ProductImage
    {
        $product->loadMissing(['partType', 'category']);

        if ($this->defaultImages->forProduct($product) === null) {
            throw new RuntimeException('Для товара не найдено дефолтное изображение. Галерея не изменена.');
        }

        $product->images()
            ->where(function ($query): void {
                $query
                    ->where('source_type', '!=', ProductImage::SOURCE_DEFAULT)
                    ->orWhereNull('source_type')
                    ->orWhere('is_default', false);
            })
            ->get()
            ->each(fn (ProductImage $image): ?bool => $image->delete());

        $default = $this->ensureDefaultImage($product->refresh(), true);

        if (! $default instanceof ProductImage) {
            throw new RuntimeException('Не удалось создать дефолтное изображение товара.');
        }

        $product->images()
            ->whereKeyNot($default->getKey())
            ->where(function ($query): void {
                $query
                    ->where('source_type', ProductImage::SOURCE_DEFAULT)
                    ->orWhere('is_default', true)
                    ->orWhere('disk', DefaultProductImageService::DISK);
            })
            ->get()
            ->each(fn (ProductImage $image): ?bool => $image->delete());

        return $this->makeMain($default->refresh());
    }

    public function makeMain(ProductImage $image): ProductImage
    {
        $image->forceFill([
            'is_main' => true,
            'is_visible' => true,
        ])->save();

        return $image->refresh();
    }

    public function setVisible(ProductImage $image, bool $visible): ProductImage
    {
        if (! $visible && $image->is_main) {
            throw new LogicException('Главное изображение нельзя скрыть. Сначала выберите другое главное изображение или удалите текущее.');
        }

        $image->forceFill(['is_visible' => $visible])->save();

        return $image->refresh();
    }

    public function deleteImage(ProductImage $image): void
    {
        $product = $image->product;
        $wasMain = (bool) $image->is_main;

        $image->delete();

        if ($wasMain && $product instanceof Product) {
            $this->promoteMainImage($product->refresh());
        }
    }

    public function promoteMainImage(Product $product): ?ProductImage
    {
        if ($this->productHasMainImage($product)) {
            return $product->images()->where('is_main', true)->where('is_visible', true)->first();
        }

        /** @var ProductImage|null $candidate */
        $candidate = $product->images()
            ->where('is_visible', true)
            ->orderByRaw("case source_type when 'manual' then 0 when 'import' then 1 when 'default' then 2 else 3 end")
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if (! $candidate instanceof ProductImage) {
            return null;
        }

        return $this->makeMain($candidate);
    }

    public function nextPosition(Product $product): int
    {
        return (int) $product->images()->max('position') + 1;
    }

    public function sourceLabel(?string $sourceType): string
    {
        return ProductImage::sourceTypeLabel($sourceType);
    }

    private function productHasMainImage(Product $product): bool
    {
        return $product->images()
            ->where('is_main', true)
            ->where('is_visible', true)
            ->exists();
    }
}
