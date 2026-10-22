<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Media\ImageDownloadService;
use App\Services\Media\MediaFileCleanupService;
use App\Services\Media\VehicleImageNotFound;
use App\Services\Media\VehicleImageQuality;
use App\Services\PublicCatalogCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportImageDownloader
{
    public function __construct(
        private readonly ImageDownloadService $downloader,
        private readonly MediaFileCleanupService $cleanup,
    ) {}

    public function download(Product $product, string $url): ProductImage
    {
        $download = function () use ($product, $url): ProductImage {
            app(CatalogImportProtections::class)->assertProductWritable($product->id);

            return $this->downloadProductImage($product, $url);
        };

        return DB::transactionLevel() > 0 ? $download() : DB::transaction($download);
    }

    private function downloadProductImage(Product $product, string $url): ProductImage
    {
        $product->loadMissing('defaultVariant');

        $existingByUrl = ProductImage::query()
            ->where('product_id', $product->getKey())
            ->where('source_url', $url)
            ->first();

        if ($existingByUrl instanceof ProductImage && is_string($existingByUrl->path) && $existingByUrl->path !== '' && Storage::disk($existingByUrl->disk ?: 'public')->exists($existingByUrl->path)) {
            if (! $this->hasManualMainImage($product)) {
                $existingByUrl->forceFill(['is_main' => true, 'is_visible' => true])->save();
            }

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

            if ($existing->source_type === ProductImage::SOURCE_IMPORT) {
                $existing->forceFill(['source_url' => $url])->save();
            }

            if (! $this->hasManualMainImage($product)) {
                $existing->forceFill(['is_main' => true, 'is_visible' => true])->save();
            }

            return $existing->refresh();
        }

        $isMain = ! $this->hasManualMainImage($product);
        $position = (int) $product->images()->max('position') + 1;

        return ProductImage::query()->create(array_merge(
            $processed->toProductImageAttributes(),
            [
                'product_id' => $product->getKey(),
                'product_variant_id' => $product->defaultVariant?->getKey(),
                'alt' => $product->title,
                'source_type' => ProductImage::SOURCE_IMPORT,
                'position' => $position,
                'is_main' => $isMain,
                'is_visible' => true,
            ],
        ));
    }

    private function hasManualMainImage(Product $product): bool
    {
        return $product->images()
            ->where('is_main', true)
            ->where('is_visible', true)
            ->where('source_type', ProductImage::SOURCE_MANUAL)
            ->exists();
    }

    public function canReuseVehicleImage(VehicleGeneration $generation): bool
    {
        if (! $generation->image || ! Storage::disk('public')->exists($generation->image)) {
            return false;
        }

        return $generation->image_source_url === null || app(VehicleImageQuality::class)->stored($generation)['state'] === 'accepted';
    }

    public function hasAutomaticVehicleImageProvenance(VehicleGeneration $generation): bool
    {
        return ! $generation->image || (trim((string) $generation->image_source_url) !== ''
            && filter_var($generation->image_source_url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($generation->image_source_url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && preg_match('/^[a-f0-9]{64}$/i', (string) $generation->image_checksum)
            && ! str_contains($generation->image, '..')
            && str_starts_with($generation->image, 'uploads/vehicles/generations/'.$generation->id.'/'));
    }

    public function downloadVehicleGenerationImage(VehicleGeneration $generation, string $url, ?string $expectedFingerprint = null, bool $preserveOldFiles = false, bool $redownload = false): VehicleGeneration
    {
        $generation = $generation->fresh()->load('model.make');
        $quality = app(VehicleImageQuality::class);
        $before = $quality->fingerprint($generation);
        if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $before)) {
            throw new \RuntimeException('План изображения устарел.');
        }
        if ($redownload && ($expectedFingerprint === null || ! $preserveOldFiles || ! $this->hasAutomaticVehicleImageProvenance($generation))) {
            throw new \RuntimeException('Повторное скачивание требует подтверждённой автоматической привязки и сохранения старых файлов.');
        }
        if ($generation->image && Storage::disk('public')->exists($generation->image) && $generation->image_source_url === null) {
            return $generation;
        }
        DB::transaction(fn () => app(CatalogImportProtections::class)->assertGenerationWritable($generation->id));
        $assessment = $redownload ? ['state' => 'missing'] : $quality->stored($generation, true);
        if ($assessment['state'] === 'accepted') {
            return $generation;
        }
        if ($assessment['state'] === 'unverified') {
            throw new VehicleImageNotFound('Существующее изображение требует проверки; повторное скачивание отложено.');
        }
        // Network and visual verification happen before the short catalogue write lock.
        $processed = $this->downloader->downloadVehicle($generation, $url, 'uploads/vehicles/generations/'.$generation->id, refresh: $redownload);
        try {
            return DB::transaction(function () use ($generation, $url, $processed, $before, $preserveOldFiles, $quality): VehicleGeneration {
                app(CatalogImportProtections::class)->assertGenerationWritable($generation->id);
                $locked = VehicleGeneration::with('model.make')->whereKey($generation->id)->lockForUpdate()->firstOrFail();
                if (! hash_equals($before, $quality->fingerprint($locked))) {
                    throw new \RuntimeException('Изображение или автомобиль изменились во время проверки.');
                }
                DB::table('vehicle_generations')->where('id', $locked->id)->update([
                    'image' => $processed->path, 'image_source_url' => $url, 'image_checksum' => $processed->checksum,
                    'image_conversions' => $processed->conversions === null ? null : json_encode($processed->conversions, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
                $this->cleanup->deletePathAfterRollback($processed->path);
                foreach ($processed->conversions ?? [] as $conversion) {
                    $this->cleanup->deletePathAfterRollback($conversion['path'], $conversion['disk']);
                }
                if (! $preserveOldFiles) {
                    $this->cleanup->deleteAfterCommit($locked->image, $locked->image_conversions);
                }
                DB::afterCommit(fn () => app(PublicCatalogCache::class)->invalidateVehicleNavigation());

                return $locked->refresh();
            });
        } catch (\Throwable $e) {
            $this->cleanup->deleteProcessedImage($processed);
            throw $e;
        }
    }

    /** Only an explicitly approved, still-rejected local file may lose its reference. Files remain for recovery. */
    public function clearRejectedVehicleImage(VehicleGeneration $generation, string $expectedFingerprint, bool $redownload = false): VehicleGeneration
    {
        return DB::transaction(function () use ($generation, $expectedFingerprint, $redownload): VehicleGeneration {
            app(CatalogImportProtections::class)->assertGenerationWritable($generation->id);
            $locked = VehicleGeneration::with('model.make')->whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $quality = app(VehicleImageQuality::class);
            if (! hash_equals($expectedFingerprint, $quality->fingerprint($locked))
                || ($redownload ? ! $this->hasAutomaticVehicleImageProvenance($locked)
                    : ($locked->image_source_url === null || $quality->stored($locked)['state'] !== 'rejected'))) {
                throw new \RuntimeException('Нет актуального доказательства неправильного автоматического изображения.');
            }
            DB::table('vehicle_generations')->where('id', $locked->id)->update([
                'image' => null, 'image_checksum' => null, 'image_conversions' => null, 'updated_at' => now(),
            ]);
            DB::afterCommit(fn () => app(PublicCatalogCache::class)->invalidateVehicleNavigation());

            return $locked->refresh();
        });
    }
}
