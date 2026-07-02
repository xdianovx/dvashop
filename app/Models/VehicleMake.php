<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\VehicleMakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'slug',
    'norm_key',
    'image',
    'position',
    'is_active',
    'meta_title',
    'meta_description',
])]
class VehicleMake extends Model
{
    /** @use HasFactory<VehicleMakeFactory> */
    use HasFactory, SoftDeletes;

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $make): void {
            $make->slug = CatalogText::slug($make->slug ?: $make->title);
            $make->norm_key = CatalogText::normKey($make->norm_key ?: $make->title);
            $make->position ??= 0;
            $make->is_active ??= true;
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
