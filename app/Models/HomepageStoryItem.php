<?php

namespace App\Models;

use App\Enums\HomepageStoryMediaType;
use Database\Factories\HomepageStoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'homepage_story_group_id',
    'media_type',
    'media_path',
    'alt_text',
    'cta_label',
    'cta_url',
    'open_in_new_tab',
    'duration_seconds',
    'is_active',
    'position',
])]
class HomepageStoryItem extends Model
{
    /** @use HasFactory<HomepageStoryItemFactory> */
    use HasFactory;

    public function group(): BelongsTo
    {
        return $this->belongsTo(HomepageStoryGroup::class, 'homepage_story_group_id');
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
        return [
            'media_type' => HomepageStoryMediaType::class,
            'open_in_new_tab' => 'boolean',
            'duration_seconds' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
