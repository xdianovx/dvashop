<?php

namespace App\Models;

use App\Services\Catalog\PartTypeTreeService;
use Database\Factories\PartTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'parent_id',
    'title',
    'slug',
    'full_slug',
    'full_title',
    'depth',
    'position',
    'is_active',
    'default_image_key',
    'product_category_id',
    'meta_title',
    'meta_description',
])]
class PartType extends Model
{
    /** @use HasFactory<PartTypeFactory> */
    use HasFactory, SoftDeletes;

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('title');
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $partType): void {
            app(PartTypeTreeService::class)->prepareForSave($partType);
        });

        static::saved(function (self $partType): void {
            if ($partType->wasChanged(['title', 'parent_id'])) {
                app(PartTypeTreeService::class)->recalculateDescendants($partType);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
