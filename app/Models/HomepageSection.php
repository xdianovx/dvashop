<?php

namespace App\Models;

use App\Enums\HomepageSectionCode;
use Database\Factories\HomepageSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'title', 'is_active', 'position'])]
class HomepageSection extends Model
{
    /** @use HasFactory<HomepageSectionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $section): void {
            $rawCode = $section->getAttributes()['code'] ?? null;

            if (! is_string($rawCode) || HomepageSectionCode::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код секции главной страницы.']);
            }

            if ($section->exists && $section->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код секции нельзя изменять.']);
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
        throw ValidationException::withMessages(['homepage_section' => 'Секцию главной страницы нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['homepage_section' => 'Секцию главной страницы нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['homepage_section' => 'Секцию главной страницы нельзя копировать.']);
    }

    /** @return Attribute<HomepageSectionCode, HomepageSectionCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): HomepageSectionCode {
                $code = is_string($value) ? HomepageSectionCode::tryFrom($value) : null;
                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код секции главной страницы.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof HomepageSectionCode ? $value->value : $value;
                if (! is_string($raw) || HomepageSectionCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код секции главной страницы.']);
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
