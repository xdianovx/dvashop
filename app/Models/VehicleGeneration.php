<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\VehicleGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'vehicle_model_id',
    'title',
    'slug',
    'norm_key',
    'years_label',
    'body',
    'image',
    'position',
    'is_active',
    'meta_title',
    'meta_description',
])]
class VehicleGeneration extends Model
{
    /** @use HasFactory<VehicleGenerationFactory> */
    use HasFactory, SoftDeletes;

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(ProductFitment::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_fitments')
            ->withPivot(['note', 'is_primary'])
            ->withTimestamps();
    }

    public function getDisplayTitleAttribute(): string
    {
        return trim(($this->model?->display_title ? $this->model->display_title.' ' : '').$this->title);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $generation): void {
            $generation->slug = CatalogText::slug($generation->slug ?: $generation->title);
            $generation->norm_key = CatalogText::normKey($generation->norm_key ?: $generation->title);
            $generation->position ??= 0;
            $generation->is_active ??= true;
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
