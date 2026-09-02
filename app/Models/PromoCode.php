<?php

namespace App\Models;

use App\Enums\PromoDiscountType;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'code',
    'name',
    'description',
    'discount_type',
    'discount_value',
    'max_discount_amount',
    'minimum_eligible_subtotal',
    'applies_to_all',
    'allow_sale_items',
    'usage_limit',
    'starts_at',
    'ends_at',
    'is_active',
])]
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory, SoftDeletes;

    public static function normalizeCode(mixed $code): string
    {
        return Str::upper(trim(is_scalar($code) ? (string) $code : ''));
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promo_code_products');
    }

    public function productCategories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'promo_code_product_categories');
    }

    public function partTypes(): BelongsToMany
    {
        return $this->belongsToMany(PartType::class, 'promo_code_part_types');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function activeRedemptions(): HasMany
    {
        return $this->redemptions()->whereNull('released_at');
    }

    public function currentStatusLabel(?int $activeUsageCount = null): string
    {
        if ($this->trashed()) {
            return 'Архивирован';
        }

        if (! $this->is_active) {
            return 'Отключён';
        }

        if ($this->starts_at?->isFuture()) {
            return 'Запланирован';
        }

        if ($this->ends_at?->isPast()) {
            return 'Истёк';
        }

        $activeUsageCount ??= isset($this->active_redemptions_count)
            ? (int) $this->active_redemptions_count
            : $this->activeRedemptions()->count();

        if ($this->usage_limit !== null && $activeUsageCount >= $this->usage_limit) {
            return 'Лимит исчерпан';
        }

        return 'Активен';
    }

    protected static function booted(): void
    {
        static::saving(function (self $promo): void {
            $promo->code = self::normalizeCode($promo->code);

            if ($promo->discount_type === PromoDiscountType::Fixed) {
                $promo->max_discount_amount = null;
            }
        });

        static::deleting(function (self $promo): void {
            if ($promo->isForceDeleting()) {
                throw ValidationException::withMessages([
                    'promo_code' => 'Безвозвратное удаление промокодов запрещено.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'discount_type' => PromoDiscountType::class,
            'discount_value' => 'decimal:4',
            'max_discount_amount' => 'decimal:2',
            'minimum_eligible_subtotal' => 'decimal:2',
            'applies_to_all' => 'boolean',
            'allow_sale_items' => 'boolean',
            'usage_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
