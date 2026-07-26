<?php

namespace App\Models;

use Database\Factories\ProductVariantOptionValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'product_variant_id',
    'product_option_group_id',
    'product_option_value_id',
])]
class ProductVariantOptionValue extends Model
{
    /** @use HasFactory<ProductVariantOptionValueFactory> */
    use HasFactory;

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(ProductOptionValue::class, 'product_option_value_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $selection): void {
            $valueGroupId = ProductOptionValue::query()
                ->whereKey($selection->product_option_value_id)
                ->value('product_option_group_id');

            if ((int) $valueGroupId !== (int) $selection->product_option_group_id) {
                throw ValidationException::withMessages([
                    'product_option_value_id' => 'Значение не принадлежит выбранной группе опций.',
                ]);
            }

            $duplicateExists = self::query()
                ->where('product_variant_id', $selection->product_variant_id)
                ->where('product_option_group_id', $selection->product_option_group_id)
                ->when($selection->exists, fn ($query) => $query->whereKeyNot($selection->getKey()))
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'product_option_group_id' => 'Для варианта можно выбрать только одно значение из каждой группы.',
                ]);
            }
        });
    }
}
