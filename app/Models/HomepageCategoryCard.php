<?php

namespace App\Models;

use App\Enums\HomepageCategoryCardCode;
use App\Enums\NavigationLinkType;
use Database\Factories\HomepageCategoryCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'title', 'link_type', 'route_name', 'url', 'open_in_new_tab', 'is_active', 'position'])]
class HomepageCategoryCard extends Model
{
    /** @use HasFactory<HomepageCategoryCardFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $card): void {
            $rawCode = $card->getAttributes()['code'] ?? null;
            if (! is_string($rawCode) || HomepageCategoryCardCode::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код категории главной страницы.']);
            }
            if ($card->exists && $card->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код категории нельзя изменять.']);
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
        throw ValidationException::withMessages(['homepage_category_card' => 'Карточку категории нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['homepage_category_card' => 'Карточку категории нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['homepage_category_card' => 'Карточку категории нельзя копировать.']);
    }

    /** @return Attribute<HomepageCategoryCardCode, HomepageCategoryCardCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): HomepageCategoryCardCode {
                $code = is_string($value) ? HomepageCategoryCardCode::tryFrom($value) : null;
                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код категории главной страницы.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof HomepageCategoryCardCode ? $value->value : $value;
                if (! is_string($raw) || HomepageCategoryCardCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код категории главной страницы.']);
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
