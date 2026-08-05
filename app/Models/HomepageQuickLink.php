<?php

namespace App\Models;

use App\Enums\HomepageQuickLinkCode;
use App\Enums\NavigationLinkType;
use Database\Factories\HomepageQuickLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'title', 'link_type', 'route_name', 'url', 'open_in_new_tab', 'is_active', 'position'])]
class HomepageQuickLink extends Model
{
    /** @use HasFactory<HomepageQuickLinkFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            $rawCode = $link->getAttributes()['code'] ?? null;
            if (! is_string($rawCode) || HomepageQuickLinkCode::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код быстрой ссылки.']);
            }
            if ($link->exists && $link->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код быстрой ссылки нельзя изменять.']);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['homepage_quick_link' => 'Быструю ссылку нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['homepage_quick_link' => 'Быструю ссылку нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['homepage_quick_link' => 'Быструю ссылку нельзя копировать.']);
    }

    /** @return Attribute<HomepageQuickLinkCode, HomepageQuickLinkCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): HomepageQuickLinkCode {
                $code = is_string($value) ? HomepageQuickLinkCode::tryFrom($value) : null;
                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код быстрой ссылки.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof HomepageQuickLinkCode ? $value->value : $value;
                if (! is_string($raw) || HomepageQuickLinkCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код быстрой ссылки.']);
                }

                return $raw;
            },
        );
    }

    protected function casts(): array
    {
        return [
            'link_type' => NavigationLinkType::class,
            'open_in_new_tab' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
