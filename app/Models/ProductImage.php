<?php

namespace App\Models;

use App\Services\Media\DefaultProductImageService;
use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaFileCleanupService;
use App\Services\Media\MediaUrlService;
use App\Services\Media\ProductGalleryService;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
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
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_MANUAL = 'manual';

    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return null;
        }

        app(ProductGalleryService::class)->deleteImage($this);

        return true;
    }

    public function deleteFromGalleryWorkflow(): ?bool
    {
        return static::withoutEvents(fn (): ?bool => parent::delete());
    }

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
            $image->source_type = is_string($image->source_type) && $image->source_type !== ''
                ? $image->source_type
                : ($image->source_url ? self::SOURCE_IMPORT : self::SOURCE_MANUAL);
            $image->is_default ??= $image->source_type === self::SOURCE_DEFAULT;
            $image->disk = is_string($image->disk) && $image->disk !== ''
                ? $image->disk
                : ($image->is_default || $image->source_type === self::SOURCE_DEFAULT ? DefaultProductImageService::DISK : 'public');
            $image->position ??= 0;
            $image->is_visible ??= true;

            if ($image->is_default) {
                $image->source_type = self::SOURCE_DEFAULT;
                $image->disk = DefaultProductImageService::DISK;
            }

            if ($image->is_main) {
                $image->is_visible = true;
            }

            app(ProductGalleryService::class)->prepareImageForSave($image);
        });

        static::saved(function (self $image): void {
            $image->processManualUploadIfNeeded();
            $image->ensureSingleMainImage();
        });

    }

    public function processManualUploadIfNeeded(): void
    {
        if ($this->isDefaultImageReference()) {
            return;
        }

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
        $sourcePath = $this->path;

        try {
            $processed = app(ImageProcessingService::class)->processStoredPublicImage(
                path: $this->path,
                profile: 'product_gallery',
                directory: 'uploads/products/'.$this->product_id,
                originalUrl: $this->source_url,
                deleteSource: false,
            );
        } catch (Throwable $e) {
            throw $e;
        }

        $cleanup = app(MediaFileCleanupService::class);
        $cleanup->scheduleReplacementCleanup(
            processed: $processed,
            sourcePath: $sourcePath,
            oldPath: is_string($oldPath) ? $oldPath : null,
            oldConversions: is_array($oldConversions) ? $oldConversions : null,
            oldDisk: is_string($oldDisk) && $oldDisk !== '' ? $oldDisk : 'public',
        );

        $duplicate = self::query()
            ->where('product_id', $this->product_id)
            ->whereKeyNot($this->getKey())
            ->where('checksum', $processed->checksum)
            ->first();

        if ($duplicate instanceof self) {
            $cleanup->deleteProcessedImage($processed);
            $this->forceFill(['checksum' => $processed->checksum]);
            $this->deleteQuietly();

            if ($this->is_main && ! $duplicate->is_main) {
                $duplicate->forceFill(['is_main' => true, 'is_visible' => true])->save();
            }

            return;
        }

        $this->forceFill($processed->toProductImageAttributes())->saveQuietly();

    }

    public function deleteFiles(): void
    {
        if ($this->isDefaultImageReference()) {
            return;
        }

        $cleanup = app(MediaFileCleanupService::class);
        $cleanup->deletePath($this->path, $this->disk ?: 'public');
        $cleanup->deleteConversions($this->conversions, $this->disk ?: 'public');
    }

    private function isDefaultImageReference(): bool
    {
        return $this->source_type === self::SOURCE_DEFAULT
            || $this->is_default
            || $this->disk === DefaultProductImageService::DISK
            || (is_string($this->path) && str_starts_with($this->path, DefaultProductImageService::DIRECTORY.'/'));
    }

    private function ensureSingleMainImage(): void
    {
        if (! $this->is_main || ! $this->product_id) {
            return;
        }

        $this->product?->ensureSingleMainImage($this);
    }

    public static function sourceTypeLabel(?string $sourceType): string
    {
        return match ($sourceType) {
            self::SOURCE_DEFAULT => 'Дефолтное',
            self::SOURCE_IMPORT => 'Импорт',
            self::SOURCE_MANUAL => 'Ручное',
            default => 'Не указан',
        };
    }

    public static function sourceTypeColor(?string $sourceType): string
    {
        return match ($sourceType) {
            self::SOURCE_DEFAULT => 'gray',
            self::SOURCE_IMPORT => 'info',
            self::SOURCE_MANUAL => 'success',
            default => 'warning',
        };
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
