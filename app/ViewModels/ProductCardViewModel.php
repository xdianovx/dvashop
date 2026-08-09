<?php

namespace App\ViewModels;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Media\MediaUrlService;
use App\Services\StorefrontProductAvailability;

class ProductCardViewModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $url,
        public readonly string $image,
        public readonly string $price,
        public readonly ?string $oldPrice,
        public readonly ?int $variantId,
        public readonly ?string $sku,
    ) {}

    public static function fromProduct(Product $product): self
    {
        $availability = app(StorefrontProductAvailability::class);

        if (! $product->relationLoaded('variants')) {
            $product->setRelation('variants', $availability->variants($product->variants())
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get());
        }

        /** @var ProductVariant|null $variant */
        $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
        /** @var ProductVariant|null $quickAddVariant */
        $quickAddVariant = $product->variants->count() === 1
            && $variant instanceof ProductVariant
            && $availability->isPurchasable($variant)
                ? $variant
                : null;

        return new self(
            id: (int) $product->getKey(),
            title: $product->title,
            url: route('products.show', $product->slug),
            image: app(MediaUrlService::class)->productMainImageUrl($product),
            price: self::formatPrice($variant?->price ?? $product->price),
            oldPrice: $variant?->old_price !== null ? self::formatPrice($variant->old_price) : ($product->old_price !== null ? self::formatPrice($product->old_price) : null),
            variantId: $quickAddVariant?->getKey(),
            sku: $variant?->sku ?: $product->sku,
        );
    }

    public static function formatPrice(mixed $price): string
    {
        return number_format((float) $price, 0, ',', ' ');
    }
}
