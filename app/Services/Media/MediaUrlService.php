<?php

namespace App\Services\Media;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use Illuminate\Support\Facades\Storage;

class MediaUrlService
{
    private const PLACEHOLDER_PATH = 'img/placeholders/image.svg';

    public function placeholderUrl(): string
    {
        return asset(self::PLACEHOLDER_PATH);
    }

    public function publicDiskUrl(?string $path, ?string $disk = 'public'): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $disk = is_string($disk) && $disk !== '' ? $disk : 'public';

        if ($disk !== 'public') {
            return null;
        }

        $storage = Storage::disk('public');

        if (! $storage->exists($path)) {
            return null;
        }

        return $storage->url($path);
    }

    public function publicDiskUrlOrPlaceholder(?string $path, ?string $disk = 'public'): string
    {
        return $this->publicDiskUrl($path, $disk) ?? $this->placeholderUrl();
    }

    public function productImageUrl(?ProductImage $image): string
    {
        if (! $image instanceof ProductImage || ! $image->is_visible) {
            return $this->placeholderUrl();
        }

        if ($image->source_type === 'default' || $image->is_default || $image->disk === DefaultProductImageService::DISK) {
            return app(DefaultProductImageService::class)->urlForPath($image->path) ?? $this->placeholderUrl();
        }

        return $this->publicDiskUrlOrPlaceholder($image->path, $image->disk ?: 'public');
    }

    public function productMainImageUrl(Product $product): string
    {
        $image = $this->resolveProductMainImage($product);

        if ($image instanceof ProductImage) {
            return $this->productImageUrl($image);
        }

        return $this->productDefaultImageUrl($product) ?? $this->placeholderUrl();
    }

    public function productDefaultImageUrl(Product | ProductCategory | null $source): ?string
    {
        $category = $source instanceof Product ? $this->resolveProductCategory($source) : $source;

        if (! $category instanceof ProductCategory) {
            return null;
        }

        $default = app(DefaultProductImageService::class)->forCategory($category);

        return is_array($default) ? $default['url'] : null;
    }

    public function vehicleGenerationImageUrl(VehicleGeneration $generation): string
    {
        return $this->publicDiskUrlOrPlaceholder($generation->image, 'public');
    }

    public function vehicleMakeImageUrl(VehicleMake $make): string
    {
        return $this->publicDiskUrlOrPlaceholder($make->image, 'public');
    }

    private function resolveProductMainImage(Product $product): ?ProductImage
    {
        $mainImage = $product->relationLoaded('mainImage') ? $product->getRelation('mainImage') : $product->mainImage()->first();

        if ($mainImage instanceof ProductImage) {
            return $mainImage;
        }

        $visibleImages = $product->relationLoaded('visibleImages')
            ? $product->getRelation('visibleImages')
            : $product->visibleImages()->limit(1)->get();

        return $visibleImages->first() instanceof ProductImage ? $visibleImages->first() : null;
    }

    private function resolveProductCategory(Product $product): ?ProductCategory
    {
        $category = $product->relationLoaded('category') ? $product->getRelation('category') : $product->category()->first();

        return $category instanceof ProductCategory ? $category : null;
    }

}
