<?php

namespace App\Models;

use App\Services\Catalog\CatalogStructureAdminService;
use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaFileCleanupService;
use App\Services\Media\MediaUrlService;
use App\Support\CatalogText;
use Database\Factories\VehicleGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'vehicle_model_id',
    'title',
    'slug',
    'norm_key',
    'years_label',
    'body',
    'image',
    'image_checksum',
    'image_conversions',
    'image_source_url',
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
class VehicleGeneration extends Model
{
    /** @use HasFactory<VehicleGenerationFactory> */
    use HasFactory, SoftDeletes;

    public function save(array $options = []): bool
    {
        return app(CatalogStructureAdminService::class)
            ->guardUniqueIdentitySave($this, fn (): bool => parent::save($options));
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(ProductFitment::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_fitments')
            ->withPivot(['note', 'is_primary'])
            ->withTimestamps();
    }

    public function getDisplayTitleAttribute(): string
    {
        return trim(($this->model?->display_title ? $this->model->display_title.' ' : '').$this->title);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $generation): void {
            $generation->slug = CatalogText::slug($generation->slug ?: $generation->title, 'generation', 100);
            $generation->norm_key = CatalogText::normKey($generation->norm_key ?: $generation->title, 'generation', 120);
            $generation->position ??= 0;
            $generation->is_active ??= true;
            app(CatalogStructureAdminService::class)->prepareVehicleGenerationForSave($generation);
            app(CatalogStructureAdminService::class)->assertVehicleGenerationIdentityAvailable($generation);
        });

        static::saved(function (self $generation): void {
            $generation->processManualImageIfNeeded();
        });

        static::deleting(fn (self $generation) => app(CatalogStructureAdminService::class)->assertVehicleCanBeDeleted($generation));
        static::restoring(fn (self $generation) => app(CatalogStructureAdminService::class)->assertVehicleCanBeRestored($generation));
    }

    public function processManualImageIfNeeded(): void
    {
        if (! $this->wasRecentlyCreated && ! $this->wasChanged('image')) {
            return;
        }

        if (! $this->image) {
            app(MediaFileCleanupService::class)->deleteAfterCommit(
                is_string($this->getOriginal('image')) ? $this->getOriginal('image') : null,
                is_array($this->getOriginal('image_conversions')) ? $this->getOriginal('image_conversions') : null,
            );

            return;
        }

        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return;
        }

        if (str_starts_with($this->image, 'uploads/vehicles/generations/'.$this->getKey().'/') && str_ends_with($this->image, '.webp')) {
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
                profile: 'vehicle_image',
                directory: 'uploads/vehicles/generations/'.$this->getKey(),
                originalUrl: $this->image_source_url,
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
        return app(MediaUrlService::class)->vehicleGenerationImageUrl($this);
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
