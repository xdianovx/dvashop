<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'product_id',
    'product_variant_id',
    'title_snapshot',
    'sku_snapshot',
    'options_snapshot',
    'image_snapshot',
    'price_snapshot',
    'old_price_snapshot',
    'total_snapshot',
    'title',
    'sku',
    'quantity',
    'stock_was_decremented',
    'stock_restored_at',
    'price',
    'total',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
        $storedTotal = $this->total_snapshot;

        return $storedTotal !== null
            ? round((float) $storedTotal, 2)
            : round((float) $this->price_snapshot * max(1, (int) $this->quantity), 2);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_was_decremented' => 'boolean',
            'stock_restored_at' => 'datetime',
            'options_snapshot' => 'array',
            'price_snapshot' => 'decimal:2',
            'old_price_snapshot' => 'decimal:2',
            'total_snapshot' => 'decimal:2',
            'price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
