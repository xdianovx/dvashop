<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
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
        return $this->hasMany(ProductImage::class);
    }

    public function mainImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_main', true)->oldest('position');
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
            'status' => ProductStatus::class,
            'stock_status' => StockStatus::class,
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'position' => 'integer',
            'is_featured' => 'boolean',
        ];
    }
}
