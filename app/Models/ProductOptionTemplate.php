<?php

namespace App\Models;

use Database\Factories\ProductOptionTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'slug',
    'applies_to',
    'part_type_id',
    'is_default',
    'is_active',
    'position',
])]
class ProductOptionTemplate extends Model
{
    /** @use HasFactory<ProductOptionTemplateFactory> */
    use HasFactory;

    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductOptionTemplateItem::class)->orderBy('position')->orderBy('id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionGroup::class, 'product_option_template_items')
            ->withPivot(['product_option_value_id', 'position'])
            ->withTimestamps();
    }

    public function values(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_option_template_items')
            ->withPivot(['product_option_group_id', 'position'])
            ->withTimestamps();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
