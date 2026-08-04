<?php

namespace App\Models;

use App\Services\Catalog\CatalogStructureAdminService;
use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaFileCleanupService;
use App\Services\Media\MediaUrlService;
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
    'seo_h1',
    'seo_text',
    'canonical_url',
    'noindex',
    'og_title',
    'og_description',
    'og_image',
])]
class VehicleMake extends Model
{
    /** @use HasFactory<VehicleMakeFactory> */
    use HasFactory, SoftDeletes;

    public function save(array $options = []): bool
    {
        return app(CatalogStructureAdminService::class)
            ->guardUniqueIdentitySave($this, fn (): bool => parent::save($options));
    }

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
            app(CatalogStructureAdminService::class)->assertVehicleMakeIdentityAvailable($make);
        });

        static::saved(function (self $make): void {
            $make->processManualImageIfNeeded();
        });

        static::deleting(fn (self $make) => app(CatalogStructureAdminService::class)->assertVehicleCanBeDeleted($make));
        static::restoring(fn (self $make) => app(CatalogStructureAdminService::class)->assertVehicleCanBeRestored($make));
    }

    public function processManualImageIfNeeded(): void
    {
        if (! $this->image) {
            if ($this->wasChanged('image')) {
                app(MediaFileCleanupService::class)->deleteAfterCommit(
                    is_string($this->getOriginal('image')) ? $this->getOriginal('image') : null,
                    is_array($this->getOriginal('image_conversions')) ? $this->getOriginal('image_conversions') : null,
                );
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
        $sourcePath = $this->image;

        try {
            $processed = app(ImageProcessingService::class)->processStoredPublicImage(
                path: $this->image,
                profile: 'brand_image',
                directory: 'uploads/vehicles/makes/'.$this->getKey(),
                deleteSource: false,
            );
        } catch (Throwable $e) {
            throw $e;
        }

        $this->forceFill([
            'image' => $processed->path,
            'image_checksum' => $processed->checksum,
            'image_conversions' => $processed->conversions,
        ])->saveQuietly();

        app(MediaFileCleanupService::class)->scheduleReplacementCleanup(
            processed: $processed,
            sourcePath: $sourcePath,
            oldPath: is_string($oldPath) ? $oldPath : null,
            oldConversions: is_array($oldConversions) ? $oldConversions : null,
        );
    }

    public function getImageUrlAttribute(): string
    {
        return app(MediaUrlService::class)->vehicleMakeImageUrl($this);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'image_conversions' => 'array',
            'noindex' => 'boolean',
        ];
    }
}
