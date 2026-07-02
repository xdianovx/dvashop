<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'number',
    'user_id',
    'cart_id',
    'status',
    'customer_name',
    'customer_phone',
    'customer_email',
    'delivery_city',
    'delivery_address',
    'comment',
    'subtotal',
    'total',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->number ??= self::makeNumber();
            $order->status ??= OrderStatus::New;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    private static function makeNumber(): string
    {
        do {
            $number = 'DVS-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }
}
