<?php

namespace App\Models;

use App\Services\Catalog\CatalogStructureAdminService;
use App\Support\CatalogText;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    'seo_h1',
    'seo_text',
    'canonical_url',
    'noindex',
    'og_title',
    'og_description',
    'og_image',
])]
class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory, SoftDeletes;

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => app(CatalogStructureAdminService::class)
            ->guardUniqueIdentitySave($this, fn (): bool => parent::save($options)));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function promoCodes(): BelongsToMany
    {
        return $this->belongsToMany(PromoCode::class, 'promo_code_product_categories');
    }

    public function partTypes(): HasMany
    {
        return $this->hasMany(PartType::class);
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

    public function getFullTitleAttribute(): string
    {
        $this->loadMissing('parent');

        if ($this->parent instanceof self) {
            return CatalogText::plain($this->parent->full_title.' / '.$this->title, 250);
        }

        return CatalogText::plain($this->title, 250);
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
            $category->slug = CatalogText::slug($category->slug ?: $category->title, 'category', 80);
            $category->position ??= 0;
            $category->is_active ??= true;
            app(CatalogStructureAdminService::class)->prepareCategoryForSave($category);
            $category->unsetRelation('parent');
            $category->rebuildPathFields();
            app(CatalogStructureAdminService::class)->assertCategoryIdentityAvailable($category);
        });

        static::saved(function (self $category): void {
            if ($category->wasChanged(['slug', 'parent_id', 'full_slug', 'depth'])) {
                $category->children()->get()->each->save();
            }
        });

        static::deleting(fn (self $category) => app(CatalogStructureAdminService::class)->assertCategoryCanBeDeleted($category));
        static::restoring(fn (self $category) => app(CatalogStructureAdminService::class)->assertCategoryCanBeRestored($category));
    }

    public function rebuildPathFields(): void
    {
        $parent = $this->parent;

        if ($parent instanceof self) {
            $this->depth = $parent->depth + 1;
            $this->full_slug = CatalogText::slugPath([$parent->full_slug, $this->slug], 250);

            return;
        }

        $this->depth = 0;
        $this->full_slug = CatalogText::slugPath([$this->slug], 250);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'depth' => 'integer',
            'noindex' => 'boolean',
        ];
    }
}
