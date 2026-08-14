<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
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
    'payment_status',
    'payment_method',
    'payment_method_title_snapshot',
    'payment_method_description_snapshot',
    'delivery_method',
    'delivery_method_title_snapshot',
    'delivery_method_description_snapshot',
    'delivery_price_mode_snapshot',
    'customer_name',
    'customer_phone',
    'customer_email',
    'customer_city',
    'customer_address',
    'customer_comment',
    'delivery_city',
    'delivery_address',
    'comment',
    'manager_comment',
    'subtotal',
    'delivery_price',
    'total',
    'total_is_final',
    'placed_at',
    'paid_at',
    'customer_email_sent_at',
    'manager_email_sent_at',
    'bitrix_sent_at',
    'bitrix_entity_id',
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

    public function recalculateTotals(): void
    {
        $subtotal = round((float) $this->items()->sum('total_snapshot'), 2);
        $deliveryPrice = round((float) $this->delivery_price, 2);

        $totalIsFinal = $this->delivery_price_mode_snapshot !== DeliveryPriceMode::OnRequest;

        $this->forceFill([
            'subtotal' => $subtotal,
            'total' => round($subtotal + $deliveryPrice, 2),
            'total_is_final' => $totalIsFinal,
        ])->save();
    }

    public function deliveryPriceText(): string
    {
        return $this->delivery_price_mode_snapshot->orderDeliveryText($this->delivery_price);
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->number ??= self::makeNumber();
            $order->status ??= OrderStatus::New;
            $order->payment_status ??= PaymentStatus::Pending;
            $order->placed_at ??= now();
        });

        static::saving(function (self $order): void {
            if ($order->payment_status === PaymentStatus::Paid && ! $order->paid_at) {
                $order->paid_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'delivery_method' => DeliveryMethod::class,
            'delivery_price_mode_snapshot' => DeliveryPriceMode::class,
            'subtotal' => 'decimal:2',
            'delivery_price' => 'decimal:2',
            'total' => 'decimal:2',
            'total_is_final' => 'boolean',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'customer_email_sent_at' => 'datetime',
            'manager_email_sent_at' => 'datetime',
            'bitrix_sent_at' => 'datetime',
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
