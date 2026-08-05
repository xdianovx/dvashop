<?php

namespace App\Models;

use Database\Factories\FaqCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'title', 'is_active', 'position'])]
class FaqCategory extends Model
{
    /** @use HasFactory<FaqCategoryFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $code = $category->getAttributes()['code'] ?? null;
            if (! is_string($code) || preg_match('/\A[a-z0-9_]+\z/', $code) !== 1 || mb_strlen($code) > 96) {
                throw ValidationException::withMessages(['code' => 'Системный код категории FAQ имеет недопустимый формат.']);
            }
            if ($category->exists && $category->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код категории FAQ нельзя изменять.']);
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(FaqItem::class);
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
        if ($this->items()->exists()) {
            throw ValidationException::withMessages([
                'faq_category' => 'Сначала удалите или перенесите все вопросы этой категории FAQ.',
            ]);
        }

        return parent::delete();
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['faq_category' => 'Категорию FAQ нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['faq_category' => 'Категорию FAQ нельзя копировать.']);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer', 'deleted_at' => 'datetime'];
    }
}
