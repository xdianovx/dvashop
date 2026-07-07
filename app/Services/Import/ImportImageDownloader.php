<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Services\Media\ImageDownloadService;
use App\Services\Media\MediaFileCleanupService;
use Illuminate\Support\Facades\Storage;

class ImportImageDownloader
{
    public function __construct(
        private readonly ImageDownloadService $downloader,
        private readonly MediaFileCleanupService $cleanup,
    ) {}

    public function download(Product $product, string $url): ProductImage
    {
        $product->loadMissing('defaultVariant');

        $existingByUrl = ProductImage::query()
            ->where('product_id', $product->getKey())
            ->where('source_url', $url)
            ->first();

        if ($existingByUrl instanceof ProductImage && is_string($existingByUrl->path) && $existingByUrl->path !== '' && Storage::disk($existingByUrl->disk ?: 'public')->exists($existingByUrl->path)) {
            return $existingByUrl->refresh();
        }

        $processed = $this->downloader->download(
            url: $url,
            profile: 'product_gallery',
            directory: 'uploads/products/'.$product->getKey(),
        );

        $existing = ProductImage::query()
            ->where('product_id', $product->getKey())
            ->where('checksum', $processed->checksum)
            ->first();

        if ($existing instanceof ProductImage) {
            $this->cleanup->deleteProcessedImage($processed);

            if (! $this->hasNonDefaultMainImage($product)) {
                $existing->forceFill(['is_main' => true, 'is_visible' => true])->save();
            }

            return $existing->refresh();
        }

        $isMain = ! $this->hasNonDefaultMainImage($product);
        $position = (int) $product->images()->max('position') + 1;

        return ProductImage::query()->create(array_merge(
            $processed->toProductImageAttributes(),
            [
                'product_id' => $product->getKey(),
                'product_variant_id' => $product->defaultVariant?->getKey(),
                'alt' => $product->title,
                'source_type' => 'import',
                'position' => $position,
                'is_main' => $isMain,
                'is_visible' => true,
            ],
        ));
    }


    private function hasNonDefaultMainImage(Product $product): bool
    {
        return $product->images()
            ->where('is_main', true)
            ->where('is_visible', true)
            ->where(function ($query): void {
                $query
                    ->where('source_type', '!=', 'default')
                    ->orWhereNull('source_type');
            })
            ->exists();
    }

    public function downloadVehicleGenerationImage(VehicleGeneration $generation, string $url): VehicleGeneration
    {
        if ($generation->image_source_url === $url && is_string($generation->image) && $generation->image !== '' && Storage::disk('public')->exists($generation->image)) {
            return $generation->refresh();
        }

        $processed = $this->downloader->download(
            url: $url,
            profile: 'vehicle_image',
            directory: 'uploads/vehicles/generations/'.$generation->getKey(),
        );

        if ($generation->image_checksum !== null && hash_equals((string) $generation->image_checksum, $processed->checksum)) {
            $this->cleanup->deleteProcessedImage($processed);

            return $generation->refresh();
        }

        $oldPath = $generation->image;
        $oldConversions = $generation->image_conversions;

        $generation->forceFill([
            'image' => $processed->path,
            'image_source_url' => $url,
            'image_checksum' => $processed->checksum,
            'image_conversions' => $processed->conversions,
        ])->save();

        if ($oldPath !== null && $oldPath !== $processed->path) {
            $this->cleanup->deletePath($oldPath, 'public');
            $this->cleanup->deleteConversions(is_array($oldConversions) ? $oldConversions : null, 'public');
        }

        return $generation->refresh();
    }
}
