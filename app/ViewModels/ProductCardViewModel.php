<?php

namespace App\ViewModels;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        /** @var ProductVariant|null $variant */
        $variant = $product->defaultVariant;
        /** @var ProductImage|null $image */
        $image = $product->images->sortByDesc('is_main')->sortBy('position')->first();

        return new self(
            id: (int) $product->getKey(),
            title: $product->title,
            url: route('products.show', $product->slug),
            image: self::imageUrl($image?->path),
            price: self::formatPrice($variant?->price ?? $product->price),
            oldPrice: $variant?->old_price !== null ? self::formatPrice($variant->old_price) : ($product->old_price !== null ? self::formatPrice($product->old_price) : null),
            variantId: $variant?->getKey(),
            sku: $variant?->sku ?: $product->sku,
        );
    }

    public static function imageUrl(?string $path): string
    {
        if (! $path) {
            return '/img/products/threshold.png';
        }

        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    public static function formatPrice(mixed $price): string
    {
        return number_format((float) $price, 0, ',', ' ');
    }
}
