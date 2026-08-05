<?php

namespace App\Models;

use App\Enums\StaticPageSectionCode;
use Database\Factories\StaticPageSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['static_page_id', 'code', 'label', 'title', 'subtitle', 'body', 'is_active', 'position'])]
class StaticPageSection extends Model
{
    /** @use HasFactory<StaticPageSectionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $section): void {
            $rawCode = $section->getAttributes()['code'] ?? null;
            $code = is_string($rawCode) ? StaticPageSectionCode::tryFrom($rawCode) : null;
            if ($code === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код блока статической страницы.']);
            }

            if ($section->exists && $section->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код блока нельзя изменять.']);
            }
            if ($section->exists && $section->isDirty('static_page_id')) {
                throw ValidationException::withMessages(['static_page_id' => 'Системный блок нельзя переносить на другую страницу.']);
            }

            $pageId = $section->getAttributes()['static_page_id'] ?? null;
            $page = is_numeric($pageId) ? StaticPage::query()->find((int) $pageId) : null;
            if ($page === null) {
                throw ValidationException::withMessages(['static_page_id' => 'Родительская статическая страница не найдена.']);
            }
            if ($code->page() !== $page->code) {
                throw ValidationException::withMessages(['static_page_id' => 'Системный блок не соответствует выбранной странице.']);
            }
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(StaticPage::class, 'static_page_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StaticPageItem::class);
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
        throw ValidationException::withMessages(['static_page_section' => 'Системный блок статической страницы нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['static_page_section' => 'Системный блок нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['static_page_section' => 'Системный блок нельзя копировать.']);
    }

    /** @return Attribute<string, never> */
    protected function displayTitle(): Attribute
    {
        return Attribute::get(fn (): string => $this->title ?: $this->label ?: $this->code->label() ?: $this->code->value);
    }

    /** @return Attribute<StaticPageSectionCode, StaticPageSectionCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): StaticPageSectionCode {
                $code = is_string($value) ? StaticPageSectionCode::tryFrom($value) : null;
                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код блока статической страницы.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof StaticPageSectionCode ? $value->value : $value;
                if (! is_string($raw) || StaticPageSectionCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код блока статической страницы.']);
                }

                return $raw;
            },
        );
    }

    protected function casts(): array
    {
        return ['static_page_id' => 'integer', 'is_active' => 'boolean', 'position' => 'integer'];
    }
}
