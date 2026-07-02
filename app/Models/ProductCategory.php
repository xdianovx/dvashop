<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[Fillable([
    'parent_id',
    'title',
    'slug',
    'full_slug',
    'depth',
    'position',
    'is_active',
    'meta_title',
    'meta_description',
])]
class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory, SoftDeletes;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }


    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function getDisplayTitleAttribute(): string
    {
        return str_repeat('— ', max(0, $this->depth)).$this->title;
    }

    /**
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        return $this->children()
            ->with('descendants')
            ->get()
            ->flatMap(fn (self $child): Collection => collect([$child->getKey()])->merge($child->descendantIds()))
            ->values()
            ->all();
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->slug = CatalogText::slug($category->slug ?: $category->title);
            $category->position ??= 0;
            $category->is_active ??= true;
            $category->rebuildPathFields();
        });

        static::saved(function (self $category): void {
            if ($category->wasChanged(['slug', 'parent_id', 'full_slug', 'depth'])) {
                $category->children()->get()->each->save();
            }
        });
    }

    public function rebuildPathFields(): void
    {
        $parent = $this->parent;

        if ($parent instanceof self) {
            $this->depth = $parent->depth + 1;
            $this->full_slug = $parent->full_slug.'/'.$this->slug;

            return;
        }

        $this->depth = 0;
        $this->full_slug = $this->slug;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'depth' => 'integer',
        ];
    }
}
