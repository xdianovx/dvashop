<?php

namespace App\Models;

use App\Enums\StaticPageItemCode;
use Database\Factories\StaticPageItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['static_page_section_id', 'code', 'label', 'title', 'text', 'is_active', 'position'])]
class StaticPageItem extends Model
{
    /** @use HasFactory<StaticPageItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $rawCode = $item->getAttributes()['code'] ?? null;
            $code = is_string($rawCode) ? StaticPageItemCode::tryFrom($rawCode) : null;
            if ($code === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код элемента статической страницы.']);
            }

            if ($item->exists && $item->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код элемента нельзя изменять.']);
            }
            if ($item->exists && $item->isDirty('static_page_section_id')) {
                throw ValidationException::withMessages(['static_page_section_id' => 'Системный элемент нельзя переносить в другой блок.']);
            }

            $sectionId = $item->getAttributes()['static_page_section_id'] ?? null;
            $section = is_numeric($sectionId) ? StaticPageSection::query()->find((int) $sectionId) : null;
            if ($section === null) {
                throw ValidationException::withMessages(['static_page_section_id' => 'Родительский блок статической страницы не найден.']);
            }
            if ($code->section() !== $section->code) {
                throw ValidationException::withMessages(['static_page_section_id' => 'Системный элемент не соответствует выбранному блоку.']);
            }
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(StaticPageSection::class, 'static_page_section_id');
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
        throw ValidationException::withMessages(['static_page_item' => 'Системный элемент статической страницы нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['static_page_item' => 'Системный элемент нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['static_page_item' => 'Системный элемент нельзя копировать.']);
    }

    /** @return Attribute<string, never> */
    protected function displayTitle(): Attribute
    {
        return Attribute::get(fn (): string => $this->title ?: $this->label ?: $this->code->label() ?: $this->code->value);
    }

    /** @return Attribute<StaticPageItemCode, StaticPageItemCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): StaticPageItemCode {
                $code = is_string($value) ? StaticPageItemCode::tryFrom($value) : null;
                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код элемента статической страницы.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof StaticPageItemCode ? $value->value : $value;
                if (! is_string($raw) || StaticPageItemCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код элемента статической страницы.']);
                }

                return $raw;
            },
        );
    }

    protected function casts(): array
    {
        return ['static_page_section_id' => 'integer', 'is_active' => 'boolean', 'position' => 'integer'];
    }
}
