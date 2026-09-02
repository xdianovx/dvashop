<?php

namespace App\Models;

use Database\Factories\PromoCodeRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'promo_code_id',
    'order_id',
    'discount_amount',
    'released_at',
])]
class PromoCodeRedemption extends Model
{
    /** @use HasFactory<PromoCodeRedemptionFactory> */
    use HasFactory;

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'released_at' => 'datetime',
        ];
    }
}
