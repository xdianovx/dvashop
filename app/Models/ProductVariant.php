<?php

namespace App\Models;

use App\Enums\StockStatus;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'sku',
    'title',
    'options',
    'price',
    'old_price',
    'stock_quantity',
    'stock_status',
    'is_default',
    'is_active',
])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_variant_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            $variant->stock_status ??= StockStatus::InStock;
            $variant->is_active ??= true;
            $variant->is_default ??= false;

            if (! $variant->exists && $variant->product_id && ! self::query()->where('product_id', $variant->product_id)->exists()) {
                $variant->is_default = true;
            }
        });

        static::saved(function (self $variant): void {
            if (! $variant->is_default) {
                return;
            }

            self::query()
                ->where('product_id', $variant->product_id)
                ->whereKeyNot($variant->getKey())
                ->update(['is_default' => false]);
        });
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'stock_status' => StockStatus::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
