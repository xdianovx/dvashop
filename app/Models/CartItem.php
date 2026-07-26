<?php

namespace App\Models;

use App\Services\Media\MediaUrlService;
use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cart_id',
    'product_id',
    'product_variant_id',
    'quantity',
    'sku_snapshot',
    'price_snapshot',
    'old_price_snapshot',
    'title_snapshot',
    'options_snapshot',
    'image_snapshot',
])]
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function optionSummary(): string
    {
        return collect(ProductVariant::optionsWithoutManagementMetadata($this->options_snapshot) ?? [])
            ->map(function (mixed $option, string|int $key): ?string {
                if (is_array($option) && filled($option['value'] ?? null)) {
                    return (string) (($option['group'] ?? null) ?: $key).': '.$option['value'];
                }

                return is_scalar($option) && filled((string) $option)
                    ? (string) $key.': '.$option
                    : null;
            })
            ->filter()
            ->implode('; ');
    }

    public function lineTotal(): float
    {
        return round((float) $this->price_snapshot * max(1, (int) $this->quantity), 2);
    }

    public function refreshSnapshotFromVariant(ProductVariant $variant): void
    {
        $variant->loadMissing(['product.mainImage', 'product.visibleImages', 'optionValues.group']);
        $product = $variant->product;

        if (! $product instanceof Product) {
            return;
        }

        $this->fill([
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'sku_snapshot' => $variant->sku ?: $product->sku,
            'title_snapshot' => $this->makeTitleSnapshot($product, $variant),
            'options_snapshot' => $this->makeOptionsSnapshot($variant),
            'image_snapshot' => app(MediaUrlService::class)->productMainImageUrl($product),
            'price_snapshot' => $variant->price ?? $product->price,
            'old_price_snapshot' => $variant->old_price ?? $product->old_price,
        ]);
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->quantity = max(1, (int) $item->quantity);
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'options_snapshot' => 'array',
            'price_snapshot' => 'decimal:2',
            'old_price_snapshot' => 'decimal:2',
        ];
    }

    private function makeTitleSnapshot(Product $product, ProductVariant $variant): string
    {
        return collect([$product->title, $variant->title])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' — ');
    }

    /** @return array<array-key, mixed> */
    private function makeOptionsSnapshot(ProductVariant $variant): array
    {
        return $variant->publicOptionsSnapshot();
    }
}
