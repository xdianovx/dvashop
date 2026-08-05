<?php

namespace App\Services\StaticContent;

use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FaqAdminService
{
    /** @param array<string, mixed> $attributes */
    public function createCategory(User $actor, array $attributes): FaqCategory
    {
        $this->authorize($actor, 'create', FaqCategory::class);
        $validated = $this->validateCategory($attributes);

        return DB::transaction(function () use ($validated): FaqCategory {
            return FaqCategory::query()->create([
                'code' => 'faq_category_'.Str::lower((string) Str::ulid()),
                ...$validated,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateCategory(User $actor, FaqCategory $category, array $attributes): FaqCategory
    {
        $this->authorize($actor, 'update', $category);

        return DB::transaction(function () use ($category, $attributes): FaqCategory {
            $locked = FaqCategory::withTrashed()->whereKey($category)->lockForUpdate()->firstOrFail();
            $validated = $this->validateCategory($attributes, $locked);
            $locked->fill($validated)->save();

            return $locked->refresh();
        });
    }

    public function setCategoryActive(User $actor, FaqCategory $category, bool $active): FaqCategory
    {
        return $this->updateCategory($actor, $category, ['is_active' => $active]);
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderCategories(User $actor, array $ids): void
    {
        $this->authorize($actor, 'reorder', FaqCategory::class);
        $validatedIds = $this->validateReorderIds($ids);

        DB::transaction(function () use ($validatedIds): void {
            $categories = FaqCategory::query()->orderBy('id')->lockForUpdate()->get();
            if ($categories->pluck('id')->sort()->values()->all() !== collect($validatedIds)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ids' => 'Сортировка должна содержать все существующие неудалённые категории FAQ.',
                ]);
            }
            foreach ($validatedIds as $position => $id) {
                FaqCategory::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    public function deleteCategory(User $actor, FaqCategory $category): bool
    {
        $this->authorize($actor, 'delete', $category);

        return DB::transaction(function () use ($category): bool {
            $locked = FaqCategory::query()->whereKey($category)->lockForUpdate()->firstOrFail();
            FaqItem::query()->where('faq_category_id', $locked->getKey())->lockForUpdate()->get();
            if ($locked->items()->exists()) {
                throw ValidationException::withMessages([
                    'faq_category' => 'Сначала удалите или перенесите все вопросы этой категории FAQ.',
                ]);
            }

            return (bool) $locked->delete();
        });
    }

    public function restoreCategory(User $actor, FaqCategory $category): FaqCategory
    {
        $this->authorize($actor, 'restore', $category);

        return DB::transaction(function () use ($category): FaqCategory {
            $locked = FaqCategory::withTrashed()->whereKey($category)->lockForUpdate()->firstOrFail();
            $locked->restore();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createItem(User $actor, FaqCategory $category, array $attributes): FaqItem
    {
        $this->authorize($actor, 'create', FaqItem::class);

        return DB::transaction(function () use ($category, $attributes): FaqItem {
            $lockedCategory = FaqCategory::query()->whereKey($category)->lockForUpdate()->first();
            if ($lockedCategory === null) {
                throw ValidationException::withMessages(['faq_category_id' => 'Выбранная категория FAQ не существует или удалена.']);
            }
            $validated = $this->validateItem($attributes);

            return FaqItem::query()->create([
                'faq_category_id' => $lockedCategory->getKey(),
                'code' => 'faq_item_'.Str::lower((string) Str::ulid()),
                ...$validated,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateItem(User $actor, FaqItem $item, array $attributes): FaqItem
    {
        $this->authorize($actor, 'update', $item);
        $snapshot = FaqItem::withTrashed()->findOrFail($item->getKey());
        $requestedCategoryId = array_key_exists('faq_category_id', $attributes)
            ? $attributes['faq_category_id']
            : $snapshot->faq_category_id;
        if (is_string($requestedCategoryId) && ctype_digit($requestedCategoryId)) {
            $requestedCategoryId = (int) $requestedCategoryId;
        }
        if (! is_int($requestedCategoryId) || $requestedCategoryId < 1) {
            throw ValidationException::withMessages(['faq_category_id' => 'Выбранная категория FAQ имеет недопустимый идентификатор.']);
        }
        $categoryIds = collect([(int) $snapshot->faq_category_id, $requestedCategoryId])->unique()->sort()->values()->all();

        return DB::transaction(function () use ($item, $attributes, $categoryIds, $requestedCategoryId): FaqItem {
            $categories = FaqCategory::query()
                ->whereKey($categoryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if (! $categories->has($requestedCategoryId)) {
                throw ValidationException::withMessages(['faq_category_id' => 'Выбранная категория FAQ не существует или удалена.']);
            }

            $locked = FaqItem::withTrashed()->whereKey($item)->lockForUpdate()->firstOrFail();
            if (! in_array((int) $locked->faq_category_id, $categoryIds, true)) {
                throw ValidationException::withMessages(['faq_category_id' => 'Категория вопроса была изменена параллельно. Повторите операцию.']);
            }

            $validated = $this->validateItem($attributes, $locked);
            $targetCategoryId = (int) ($validated['faq_category_id'] ?? $locked->faq_category_id);
            if (! $categories->has($targetCategoryId)) {
                throw ValidationException::withMessages(['faq_category_id' => 'Выбранная категория FAQ не существует или удалена.']);
            }

            if ($targetCategoryId !== (int) $locked->faq_category_id) {
                FaqItem::withTrashed()->whereKey($locked)->update([
                    ...$validated,
                    'faq_category_id' => $targetCategoryId,
                    'updated_at' => now(),
                ]);

                return FaqItem::withTrashed()->with('category')->findOrFail($locked->getKey());
            }

            unset($validated['faq_category_id']);
            $locked->fill($validated)->save();

            return $locked->refresh()->load('category');
        });
    }

    public function setItemActive(User $actor, FaqItem $item, bool $active): FaqItem
    {
        return $this->updateItem($actor, $item, ['is_active' => $active]);
    }

    public function setItemFeatured(User $actor, FaqItem $item, bool $featured): FaqItem
    {
        return $this->updateItem($actor, $item, ['is_featured' => $featured]);
    }

    public function deleteItem(User $actor, FaqItem $item): bool
    {
        $this->authorize($actor, 'delete', $item);

        return DB::transaction(function () use ($item): bool {
            $locked = FaqItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();

            return (bool) $locked->delete();
        });
    }

    public function restoreItem(User $actor, FaqItem $item): FaqItem
    {
        $this->authorize($actor, 'restore', $item);
        $snapshot = FaqItem::withTrashed()->findOrFail($item->getKey());

        return DB::transaction(function () use ($item, $snapshot): FaqItem {
            $category = FaqCategory::query()->whereKey($snapshot->faq_category_id)->lockForUpdate()->first();
            if ($category === null) {
                throw ValidationException::withMessages(['faq_category_id' => 'Нельзя восстановить вопрос: его категория FAQ удалена.']);
            }
            $locked = FaqItem::withTrashed()->whereKey($item)->lockForUpdate()->firstOrFail();
            if ((int) $locked->faq_category_id !== (int) $snapshot->faq_category_id) {
                throw ValidationException::withMessages(['faq_category_id' => 'Категория вопроса была изменена параллельно. Повторите операцию.']);
            }
            $locked->restore();

            return $locked->refresh()->load('category');
        });
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateCategory(array $attributes, ?FaqCategory $category = null): array
    {
        $fields = ['title', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'категории FAQ');
        $candidate = $category === null
            ? array_merge(['is_active' => true, 'position' => 0], $attributes)
            : array_merge($category->only($fields), $attributes);
        $candidate['title'] = $this->trimNullable($candidate['title'] ?? null);

        $validated = Validator::make($candidate, [
            'title' => ['required', 'string', 'max:255', $this->plainTextRule('Название категории')],
            'is_active' => ['required', $this->strictBooleanRule()],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateItem(array $attributes, ?FaqItem $item = null): array
    {
        $fields = $item === null
            ? ['question', 'answer', 'is_featured', 'is_active', 'position']
            : ['faq_category_id', 'question', 'answer', 'is_featured', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'вопросе FAQ');
        $defaults = ['is_featured' => false, 'is_active' => true, 'position' => 0];
        $candidate = $item === null
            ? array_merge($defaults, $attributes)
            : array_merge($item->only($fields), $attributes);
        foreach (['question', 'answer'] as $field) {
            $candidate[$field] = $this->trimNullable($candidate[$field] ?? null);
        }
        if ($item === null) {
            unset($candidate['faq_category_id']);
        }

        $rules = [
            'question' => ['required', 'string', 'max:500', $this->plainTextRule('Вопрос')],
            'answer' => ['required', 'string', 'max:5000', $this->plainTextRule('Ответ')],
            'is_featured' => ['required', $this->strictBooleanRule()],
            'is_active' => ['required', $this->strictBooleanRule()],
            'position' => ['required', 'integer', 'min:0'],
        ];
        if ($item !== null) {
            $rules['faq_category_id'] = ['required', 'integer', 'min:1'];
        }

        $validated = Validator::make($candidate, $rules, $this->messages())->validate();
        if (array_key_exists('faq_category_id', $validated)) {
            $validated['faq_category_id'] = (int) $validated['faq_category_id'];
        }
        $validated['is_featured'] = (bool) $validated['is_featured'];
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<int|string, mixed> $ids
     * @return list<int>
     */
    private function validateReorderIds(array $ids): array
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => 'Передайте категории FAQ для сортировки.']);
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw ValidationException::withMessages(['ids' => 'Идентификаторы сортировки должны быть положительными целыми числами.']);
            }
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['ids' => 'Список сортировки содержит повторяющиеся записи.']);
        }

        return array_values($ids);
    }

    /** @param array<string, mixed> $attributes
     * @param  list<string>  $allowed
     */
    private function rejectUnexpected(array $attributes, array $allowed, string $entity): void
    {
        $unexpected = array_values(array_diff(array_keys($attributes), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [$field => "Поле «{$field}» нельзя изменять в {$entity}."])
                ->all());
        }
    }

    private function trimNullable(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function plainTextRule(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            if (is_string($value) && strip_tags($value) !== $value) {
                $fail("{$label} должен содержать обычный текст без HTML.");
            }
        };
    }

    private function strictBooleanRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail('Поле «:attribute» должно быть логическим значением.');
            }
        };
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'Поле «:attribute» обязательно.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'integer' => 'Поле «:attribute» должно быть целым числом.',
            'min' => 'Поле «:attribute» содержит недопустимое значение.',
        ];
    }

    private function authorize(User $actor, string $ability, mixed $target): void
    {
        if (! $actor->can($ability, $target)) {
            throw new AuthorizationException('Недостаточно прав для управления FAQ.');
        }
    }
}
