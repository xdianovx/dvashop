<?php

namespace App\Models;

use Database\Factories\ProductOptionGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'slug',
    'code',
    'description',
    'input_type',
    'applies_to',
    'is_required',
    'is_active',
    'position',
])]
class ProductOptionGroup extends Model
{
    /** @use HasFactory<ProductOptionGroupFactory> */
    use HasFactory;

    public const APPLIES_ALL = 'all';

    public const APPLIES_AUTO_PART = 'auto_part';

    public const APPLIES_GENERIC = 'generic';

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('position')->orderBy('id');
    }

    public function templateItems(): HasMany
    {
        return $this->hasMany(ProductOptionTemplateItem::class)->orderBy('position')->orderBy('id');
    }

    public function variantOptionValues(): HasMany
    {
        return $this->hasMany(ProductVariantOptionValue::class);
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
