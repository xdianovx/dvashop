<?php

namespace App\Models;

use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaFileCleanupService;
use App\Services\Media\MediaUrlService;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'product_id',
    'product_variant_id',
    'disk',
    'path',
    'original_path',
    'source_url',
    'source_type',
    'mime',
    'width',
    'height',
    'size',
    'checksum',
    'conversions',
    'alt',
    'position',
    'is_default',
    'is_main',
    'is_visible',
])]
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $image): void {
            $image->disk = is_string($image->disk) && $image->disk !== '' ? $image->disk : 'public';
            $image->source_type = is_string($image->source_type) && $image->source_type !== ''
                ? $image->source_type
                : ($image->source_url ? 'import' : 'manual');
            $image->position ??= 0;
            $image->is_default ??= false;
            $image->is_visible ??= true;

            if ($image->is_main) {
                $image->is_visible = true;
            }
        });

        static::saved(function (self $image): void {
            $image->processManualUploadIfNeeded();
            $image->ensureSingleMainImage();
        });

        static::deleted(function (self $image): void {
            $image->deleteFiles();
        });
    }

    public function processManualUploadIfNeeded(): void
    {
        if (! $this->product_id || ! $this->path || filter_var($this->path, FILTER_VALIDATE_URL)) {
            return;
        }

        $diskName = $this->disk ?: 'public';
        if ($diskName !== 'public') {
            return;
        }

        if ($this->mime === 'image/webp' && $this->checksum && str_starts_with($this->path, 'uploads/products/'.$this->product_id.'/')) {
            return;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($this->path)) {
            return;
        }

        $oldPath = $this->getOriginal('path');
        $oldConversions = $this->getOriginal('conversions');
        $oldDisk = $this->getOriginal('disk') ?: 'public';

        try {
            $processed = app(ImageProcessingService::class)->processStoredPublicImage(
                path: $this->path,
                profile: 'product_gallery',
                directory: 'uploads/products/'.$this->product_id,
                originalUrl: $this->source_url,
            );
        } catch (Throwable $e) {
            throw $e;
        }

        $duplicate = self::query()
            ->where('product_id', $this->product_id)
            ->whereKeyNot($this->getKey())
            ->where('checksum', $processed->checksum)
            ->first();

        if ($duplicate instanceof self) {
            app(MediaFileCleanupService::class)->deleteProcessedImage($processed);
            $this->deleteQuietly();

            if ($this->is_main && ! $duplicate->is_main) {
                $duplicate->forceFill(['is_main' => true, 'is_visible' => true])->save();
            }

            return;
        }

        $this->forceFill($processed->toProductImageAttributes())->saveQuietly();

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $processed->path) {
            $cleanup = app(MediaFileCleanupService::class);
            $cleanup->deletePath($oldPath, is_string($oldDisk) && $oldDisk !== '' ? $oldDisk : 'public');
            $cleanup->deleteConversions(is_array($oldConversions) ? $oldConversions : null, is_string($oldDisk) && $oldDisk !== '' ? $oldDisk : 'public');
        }
    }

    public function deleteFiles(): void
    {
        $cleanup = app(MediaFileCleanupService::class);
        $cleanup->deletePath($this->path, $this->disk ?: 'public');
        $cleanup->deleteConversions($this->conversions, $this->disk ?: 'public');
    }

    private function ensureSingleMainImage(): void
    {
        if (! $this->is_main || ! $this->product_id) {
            return;
        }

        $this->product?->ensureSingleMainImage($this);
    }

    public function getUrlAttribute(): string
    {
        return app(MediaUrlService::class)->productImageUrl($this);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_default' => 'boolean',
            'is_main' => 'boolean',
            'is_visible' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
            'conversions' => 'array',
        ];
    }
}
