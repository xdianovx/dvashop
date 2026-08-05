<?php

namespace App\Models;

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use Database\Factories\SiteNavigationItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'code',
    'zone',
    'title',
    'link_type',
    'route_name',
    'url',
    'open_in_new_tab',
    'is_active',
    'position',
])]
class SiteNavigationItem extends Model
{
    /** @use HasFactory<SiteNavigationItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->exists && $item->isDirty('code')) {
                throw ValidationException::withMessages([
                    'code' => 'Стабильный код существующего пункта навигации нельзя изменять.',
                ]);
            }
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('zone')->orderBy('position')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'zone' => NavigationZone::class,
            'link_type' => NavigationLinkType::class,
            'open_in_new_tab' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
