<?php

namespace App\Models;

use Database\Factories\ProductOptionTemplateItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'product_option_template_id',
    'product_option_group_id',
    'product_option_value_id',
    'position',
])]
class ProductOptionTemplateItem extends Model
{
    /** @use HasFactory<ProductOptionTemplateItemFactory> */
    use HasFactory;

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductOptionTemplate::class, 'product_option_template_id');
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
        static::saving(function (self $item): void {
            $valueGroupId = ProductOptionValue::query()
                ->whereKey($item->product_option_value_id)
                ->value('product_option_group_id');

            if ((int) $valueGroupId !== (int) $item->product_option_group_id) {
                throw ValidationException::withMessages([
                    'product_option_value_id' => 'Значение не принадлежит выбранной группе опций.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
