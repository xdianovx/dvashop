<?php

namespace App\Models;

use App\Services\Media\ImageProcessingService;
use App\Services\Media\MediaFileCleanupService;
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
])]
class VehicleGeneration extends Model
{
    /** @use HasFactory<VehicleGenerationFactory> */
    use HasFactory, SoftDeletes;

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
        });

        static::saved(function (self $generation): void {
            $generation->processManualImageIfNeeded();
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

        if (str_starts_with($this->image, 'uploads/vehicles/generations/'.$this->getKey().'/') && str_ends_with($this->image, '.webp')) {
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
                profile: 'vehicle_image',
                directory: 'uploads/vehicles/generations/'.$this->getKey(),
                originalUrl: $this->image_source_url,
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
