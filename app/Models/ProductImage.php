<?php

namespace App\Models;

use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaFileCleanupService;
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
    'mime',
    'width',
    'height',
    'size',
    'checksum',
    'conversions',
    'alt',
    'position',
    'is_main',
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
                $duplicate->forceFill(['is_main' => true])->save();
            }

            return;
        }

        $this->forceFill($processed->toProductImageAttributes())->saveQuietly();
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

        self::query()
            ->where('product_id', $this->product_id)
            ->whereKeyNot($this->getKey())
            ->where('is_main', true)
            ->update(['is_main' => false]);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_main' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
            'conversions' => 'array',
        ];
    }
}
