<?php

namespace App\Models;

use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaFileCleanupService;
use App\Support\CatalogText;
use Database\Factories\VehicleMakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'title',
    'slug',
    'norm_key',
    'image',
    'image_checksum',
    'image_conversions',
    'position',
    'is_active',
    'meta_title',
    'meta_description',
])]
class VehicleMake extends Model
{
    /** @use HasFactory<VehicleMakeFactory> */
    use HasFactory, SoftDeletes;

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $make): void {
            $make->slug = CatalogText::slug($make->slug ?: $make->title, 'make', 100);
            $make->norm_key = CatalogText::normKey($make->norm_key ?: $make->title, 'make', 100);
            $make->position ??= 0;
            $make->is_active ??= true;
        });

        static::saved(function (self $make): void {
            $make->processManualImageIfNeeded();
        });
    }


    public function processManualImageIfNeeded(): void
    {
        if (! $this->image) {
            if ($this->wasChanged('image')) {
                $cleanup = app(MediaFileCleanupService::class);
                $cleanup->deletePath($this->getOriginal('image'), 'public');
                $cleanup->deleteConversions(is_array($this->getOriginal('image_conversions')) ? $this->getOriginal('image_conversions') : null, 'public');
            }

            return;
        }

        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return;
        }

        if (str_starts_with($this->image, 'uploads/vehicles/makes/'.$this->getKey().'/') && str_ends_with($this->image, '.webp')) {
            return;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($this->image)) {
            return;
        }

        $oldPath = $this->getOriginal('image');
        $oldConversions = $this->getOriginal('image_conversions');

        try {
            $processed = app(ImageProcessingService::class)->processStoredPublicImage(
                path: $this->image,
                profile: 'brand_image',
                directory: 'uploads/vehicles/makes/'.$this->getKey(),
            );
        } catch (Throwable $e) {
            throw $e;
        }

        $this->forceFill([
            'image' => $processed->path,
            'image_checksum' => $processed->checksum,
            'image_conversions' => $processed->conversions,
        ])->saveQuietly();

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $processed->path) {
            $cleanup = app(MediaFileCleanupService::class);
            $cleanup->deletePath($oldPath, 'public');
            $cleanup->deleteConversions(is_array($oldConversions) ? $oldConversions : null, 'public');
        }
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'image_conversions' => 'array',
        ];
    }
}
