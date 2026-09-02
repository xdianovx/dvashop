<?php

namespace App\Models;

use App\Services\Catalog\CatalogStructureAdminService;
use App\Services\Catalog\PartTypeTreeService;
use Database\Factories\PartTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    'seo_h1',
    'seo_text',
    'canonical_url',
    'noindex',
    'og_title',
    'og_description',
    'og_image',
])]
class PartType extends Model
{
    /** @use HasFactory<PartTypeFactory> */
    use HasFactory, SoftDeletes;

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => app(CatalogStructureAdminService::class)
            ->guardUniqueIdentitySave($this, fn (): bool => parent::save($options)));
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

    public function promoCodes(): BelongsToMany
    {
        return $this->belongsToMany(PromoCode::class, 'promo_code_part_types');
    }

    public function optionTemplates(): HasMany
    {
        return $this->hasMany(ProductOptionTemplate::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $partType): void {
            app(PartTypeTreeService::class)->prepareForSave($partType);
            app(CatalogStructureAdminService::class)->assertPartTypeIdentityAvailable($partType);
        });

        static::saved(function (self $partType): void {
            if ($partType->wasChanged(['title', 'parent_id'])) {
                app(PartTypeTreeService::class)->recalculateDescendants($partType);
            }
        });

        static::deleting(fn (self $partType) => app(CatalogStructureAdminService::class)->assertPartTypeCanBeDeleted($partType));
        static::restoring(fn (self $partType) => app(CatalogStructureAdminService::class)->assertPartTypeCanBeRestored($partType));
    }

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
            'noindex' => 'boolean',
        ];
    }
}
