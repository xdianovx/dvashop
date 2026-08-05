<?php

namespace App\Models;

use Database\Factories\FaqItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable(['faq_category_id', 'code', 'question', 'answer', 'is_featured', 'is_active', 'position'])]
class FaqItem extends Model
{
    /** @use HasFactory<FaqItemFactory> */
    use HasFactory, SoftDeletes {
        restore as private restoreUsingSoftDeletes;
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $code = $item->getAttributes()['code'] ?? null;
            if (! is_string($code) || preg_match('/\A[a-z0-9_]+\z/', $code) !== 1 || mb_strlen($code) > 96) {
                throw ValidationException::withMessages(['code' => 'Системный код вопроса FAQ имеет недопустимый формат.']);
            }
            if ($item->exists && $item->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код вопроса FAQ нельзя изменять.']);
            }
            if ($item->exists && $item->isDirty('faq_category_id')) {
                throw ValidationException::withMessages(['faq_category_id' => 'Переносить вопрос между категориями можно только через административный сервис.']);
            }

            $categoryId = $item->getAttributes()['faq_category_id'] ?? null;
            $categoryExists = is_numeric($categoryId)
                && FaqCategory::query()->whereKey((int) $categoryId)->exists();
            if (! $categoryExists) {
                throw ValidationException::withMessages(['faq_category_id' => 'Выбранная категория FAQ не существует или удалена.']);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function restore(): bool
    {
        $categoryExists = FaqCategory::query()->whereKey($this->faq_category_id)->exists();
        if (! $categoryExists) {
            throw ValidationException::withMessages(['faq_category_id' => 'Нельзя восстановить вопрос: его категория FAQ удалена.']);
        }

        return $this->restoreUsingSoftDeletes();
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['faq_item' => 'Вопрос FAQ нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['faq_item' => 'Вопрос FAQ нельзя копировать.']);
    }

    protected function casts(): array
    {
        return [
            'faq_category_id' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }
}
