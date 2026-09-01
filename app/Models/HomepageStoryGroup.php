<?php

namespace App\Models;

use Database\Factories\HomepageStoryGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'cover_image_path', 'is_active', 'position'])]
class HomepageStoryGroup extends Model
{
    /** @use HasFactory<HomepageStoryGroupFactory> */
    use HasFactory;

    public function items(): HasMany
    {
        return $this->hasMany(HomepageStoryItem::class)->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }
}
