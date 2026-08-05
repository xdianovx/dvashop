<?php

namespace App\Models;

use App\Enums\StaticPageCode;
use Database\Factories\StaticPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'title', 'subtitle', 'primary_action_label', 'secondary_action_label', 'is_active', 'position'])]
class StaticPage extends Model
{
    /** @use HasFactory<StaticPageFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            $rawCode = $page->getAttributes()['code'] ?? null;

            if (! is_string($rawCode) || StaticPageCode::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код статической страницы.']);
            }

            if ($page->exists && $page->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код статической страницы нельзя изменять.']);
            }
        });
    }

    public function sections(): HasMany
    {
        return $this->hasMany(StaticPageSection::class);
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
        throw ValidationException::withMessages(['static_page' => 'Системную статическую страницу нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['static_page' => 'Системную статическую страницу нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['static_page' => 'Системную статическую страницу нельзя копировать.']);
    }

    /** @return Attribute<StaticPageCode, StaticPageCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): StaticPageCode {
                $code = is_string($value) ? StaticPageCode::tryFrom($value) : null;
                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код статической страницы.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof StaticPageCode ? $value->value : $value;
                if (! is_string($raw) || StaticPageCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код статической страницы.']);
                }

                return $raw;
            },
        );
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }
}
