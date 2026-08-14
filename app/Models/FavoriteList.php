<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'token',
    'expires_at',
])]
class FavoriteList extends Model
{
    use MassPrunable;

    public function items(): HasMany
    {
        return $this->hasMany(FavoriteItem::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function prunable(): Builder
    {
        return self::query()->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    protected static function booted(): void
    {
        static::creating(function (self $list): void {
            $list->token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
