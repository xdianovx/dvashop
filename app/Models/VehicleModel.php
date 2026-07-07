<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\VehicleModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'vehicle_make_id',
    'title',
    'slug',
    'norm_key',
    'position',
    'is_active',
    'meta_title',
    'meta_description',
])]
class VehicleModel extends Model
{
    /** @use HasFactory<VehicleModelFactory> */
    use HasFactory, SoftDeletes;

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(VehicleGeneration::class);
    }

    public function getDisplayTitleAttribute(): string
    {
        return trim(($this->make?->title ? $this->make->title.' ' : '').$this->title);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $model->slug = CatalogText::slug($model->slug ?: $model->title, 'model', 100);
            $model->norm_key = CatalogText::normKey($model->norm_key ?: $model->title, 'model', 100);
            $model->position ??= 0;
            $model->is_active ??= true;
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
