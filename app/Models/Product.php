<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Services\Media\MediaUrlService;
use App\Support\CatalogText;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'product_category_id',
    'product_type',
    'part_type_id',
    'title',
    'slug',
    'sku',
    'status',
    'short_description',
    'description',
    'price',
    'old_price',
    'stock_status',
    'position',
    'is_featured',
    'meta_title',
    'meta_description',
    'import_key',
    'import_source',
    'last_import_run_id',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    public function isAutoPart(): bool
    {
        return $this->product_type === ProductType::AutoPart;
    }

    public function isGeneric(): bool
    {
        return $this->product_type === ProductType::Generic;
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function visibleImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('is_visible', true)->orderBy('position')->orderBy('id');
    }

    public function mainImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)
            ->where('is_visible', true)
            ->where('is_main', true)
            ->orderByRaw("case source_type when 'manual' then 0 when 'import' then 1 when 'default' then 2 else 3 end")
            ->oldest('position')
            ->oldest('id');
    }

    public function manualImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('source_type', ProductImage::SOURCE_MANUAL);
    }

    public function importImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('source_type', ProductImage::SOURCE_IMPORT);
    }

    public function defaultImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('source_type', ProductImage::SOURCE_DEFAULT)->where('is_default', true);
    }

    public function ensureSingleMainImage(?ProductImage $mainImage = null): void
    {
        $mainImage ??= $this->images()->where('is_main', true)->latest('updated_at')->latest('id')->first();

        if (! $mainImage instanceof ProductImage) {
            return;
        }

        $this->images()
            ->whereKeyNot($mainImage->getKey())
            ->where('is_main', true)
            ->update(['is_main' => false]);

        if (! $mainImage->is_visible) {
            $mainImage->forceFill(['is_visible' => true])->saveQuietly();
        }
    }

    public function getMainImageUrlAttribute(): string
    {
        return app(MediaUrlService::class)->productMainImageUrl($this);
    }

    public function getDefaultImageUrlAttribute(): string
    {
        return app(MediaUrlService::class)->productDefaultImageUrl($this) ?? app(MediaUrlService::class)->placeholderUrl();
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(ProductFitment::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function vehicleGenerations(): BelongsToMany
    {
        return $this->belongsToMany(VehicleGeneration::class, 'product_fitments')
            ->withPivot(['note', 'is_primary'])
            ->withTimestamps();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', ProductStatus::Active->value);
    }

    #[Scope]
    protected function withoutVisibleImages(Builder $query): void
    {
        $query->whereDoesntHave('images', fn (Builder $imageQuery): Builder => $imageQuery->where('is_visible', true));
    }

    #[Scope]
    protected function withImageSource(Builder $query, string $sourceType): void
    {
        $query->whereHas('images', fn (Builder $imageQuery): Builder => $imageQuery->where('source_type', $sourceType));
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->slug = CatalogText::slug($product->slug ?: $product->title, 'product', 150);
            $product->status ??= ProductStatus::Draft;
            $product->stock_status ??= StockStatus::InStock;
            $product->position ??= 0;
            $product->is_featured ??= false;
        });
    }

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'status' => ProductStatus::class,
            'stock_status' => StockStatus::class,
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'position' => 'integer',
            'is_featured' => 'boolean',
        ];
    }
}
