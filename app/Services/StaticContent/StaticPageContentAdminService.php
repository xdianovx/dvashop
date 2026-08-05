<?php

namespace App\Services\StaticContent;

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaticPageContentAdminService
{
    /** @param array<string, mixed> $attributes */
    public function updatePage(User $actor, StaticPage $page, array $attributes): StaticPage
    {
        /** @var StaticPage $updated */
        $updated = $this->updateRecord(
            $actor,
            $page,
            $attributes,
            fn (array $data, Model $record): array => $this->validatePage($data, $record),
        );

        return $updated;
    }

    public function setPageActive(User $actor, StaticPage $page, bool $active): StaticPage
    {
        /** @var StaticPage $updated */
        $updated = $this->setActive($actor, $page, $active);

        return $updated;
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderPages(User $actor, array $ids): void
    {
        $this->authorize($actor, 'reorder', StaticPage::class);
        $validatedIds = $this->validateReorderIds($ids, 'статические страницы');

        DB::transaction(function () use ($validatedIds): void {
            $records = StaticPage::query()->orderBy('id')->lockForUpdate()->get();
            if ($records->pluck('id')->sort()->values()->all() !== collect($validatedIds)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ids' => 'Сортировка должна содержать все существующие статические страницы без пропусков и посторонних записей.',
                ]);
            }

            foreach ($validatedIds as $position => $id) {
                StaticPage::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateSection(User $actor, StaticPageSection $section, array $attributes): StaticPageSection
    {
        /** @var StaticPageSection $updated */
        $updated = $this->updateRecord(
            $actor,
            $section,
            $attributes,
            fn (array $data, Model $record): array => $this->validateSection($data, $record),
        );

        return $updated;
    }

    public function setSectionActive(User $actor, StaticPageSection $section, bool $active): StaticPageSection
    {
        /** @var StaticPageSection $updated */
        $updated = $this->setActive($actor, $section, $active);

        return $updated;
    }

    /** @param array<string, mixed> $attributes */
    public function updateItem(User $actor, StaticPageItem $item, array $attributes): StaticPageItem
    {
        /** @var StaticPageItem $updated */
        $updated = $this->updateRecord(
            $actor,
            $item,
            $attributes,
            fn (array $data, Model $record): array => $this->validateItem($data, $record),
        );

        return $updated;
    }

    public function setItemActive(User $actor, StaticPageItem $item, bool $active): StaticPageItem
    {
        /** @var StaticPageItem $updated */
        $updated = $this->setActive($actor, $item, $active);

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Closure(array<string, mixed>, Model): array<string, mixed>  $validator
     */
    private function updateRecord(User $actor, Model $record, array $attributes, Closure $validator): Model
    {
        $this->authorize($actor, 'update', $record);

        return DB::transaction(function () use ($record, $attributes, $validator): Model {
            $locked = $record->newQuery()->whereKey($record)->lockForUpdate()->firstOrFail();
            $validated = $validator($attributes, $locked);
            $locked->fill($validated)->save();

            return $locked->refresh();
        });
    }

    private function setActive(User $actor, Model $record, bool $active): Model
    {
        $this->authorize($actor, 'update', $record);

        return DB::transaction(function () use ($record, $active): Model {
            $locked = $record->newQuery()->whereKey($record)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => $active])->save();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validatePage(array $attributes, Model $record): array
    {
        $fields = ['code', 'title', 'subtitle', 'primary_action_label', 'secondary_action_label', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'статической странице');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);
        foreach (['title', 'subtitle', 'primary_action_label', 'secondary_action_label'] as $field) {
            $candidate[$field] = $this->trimNullable($candidate[$field] ?? null);
        }
        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'статической страницы');

        $plain = $this->plainTextRule('Поле');
        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(StaticPageCode::class)],
            'title' => ['required', 'string', 'max:255', $plain],
            'subtitle' => ['nullable', 'string', 'max:5000', $plain],
            'primary_action_label' => ['nullable', 'string', 'max:255', $plain],
            'secondary_action_label' => ['nullable', 'string', 'max:255', $plain],
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
    private function validateSection(array $attributes, Model $record): array
    {
        $fields = ['static_page_id', 'code', 'label', 'title', 'subtitle', 'body', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'блоке статической страницы');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);
        foreach (['label', 'title', 'subtitle', 'body'] as $field) {
            $candidate[$field] = $this->trimNullable($candidate[$field] ?? null);
        }
        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'блока');
        $this->ensureParentUnchanged('static_page_id', $candidate['static_page_id'] ?? null, $record, 'Системный блок нельзя переносить на другую страницу.');

        $plain = $this->plainTextRule('Поле');
        $validated = Validator::make($candidate, [
            'static_page_id' => ['required', 'integer', 'min:1', 'exists:static_pages,id'],
            'code' => ['required', Rule::enum(StaticPageSectionCode::class)],
            'label' => ['nullable', 'string', 'max:255', $plain],
            'title' => ['nullable', 'string', 'max:255', $plain],
            'subtitle' => ['nullable', 'string', 'max:5000', $plain],
            'body' => ['nullable', 'string', 'max:10000', $plain],
            'is_active' => ['required', $this->strictBooleanRule()],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        $code = StaticPageSectionCode::from($validated['code']);
        $page = StaticPage::query()->findOrFail((int) $validated['static_page_id']);
        if ($code->page() !== $page->code) {
            throw ValidationException::withMessages(['static_page_id' => 'Системный блок не соответствует выбранной странице.']);
        }

        $validated['static_page_id'] = (int) $validated['static_page_id'];
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateItem(array $attributes, Model $record): array
    {
        $fields = ['static_page_section_id', 'code', 'label', 'title', 'text', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'элементе статической страницы');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);
        foreach (['label', 'title', 'text'] as $field) {
            $candidate[$field] = $this->trimNullable($candidate[$field] ?? null);
        }
        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'элемента');
        $this->ensureParentUnchanged('static_page_section_id', $candidate['static_page_section_id'] ?? null, $record, 'Системный элемент нельзя переносить в другой блок.');

        $plain = $this->plainTextRule('Поле');
        $validated = Validator::make($candidate, [
            'static_page_section_id' => ['required', 'integer', 'min:1', 'exists:static_page_sections,id'],
            'code' => ['required', Rule::enum(StaticPageItemCode::class)],
            'label' => ['nullable', 'string', 'max:255', $plain],
            'title' => ['nullable', 'string', 'max:500', $plain],
            'text' => ['nullable', 'string', 'max:10000', $plain],
            'is_active' => ['required', $this->strictBooleanRule()],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        if ($validated['label'] === null && $validated['title'] === null && $validated['text'] === null) {
            throw ValidationException::withMessages(['text' => 'Заполните хотя бы одно поле: подпись, заголовок или текст.']);
        }

        $code = StaticPageItemCode::from($validated['code']);
        $section = StaticPageSection::query()->findOrFail((int) $validated['static_page_section_id']);
        if ($code->section() !== $section->code) {
            throw ValidationException::withMessages(['static_page_section_id' => 'Системный элемент не соответствует выбранному блоку.']);
        }

        $validated['static_page_section_id'] = (int) $validated['static_page_section_id'];
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<int|string, mixed> $ids
     * @return list<int>
     */
    private function validateReorderIds(array $ids, string $label): array
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => "Передайте {$label} для сортировки."]);
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

    private function ensureCodeUnchanged(mixed $candidate, Model $record, string $entity): void
    {
        if ($candidate !== $record->getRawOriginal('code')) {
            throw ValidationException::withMessages(['code' => "Системный код {$entity} нельзя изменять."]);
        }
    }

    private function ensureParentUnchanged(string $field, mixed $candidate, Model $record, string $message): void
    {
        if ((int) $candidate !== (int) $record->getRawOriginal($field)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function backedValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
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
                $fail("{$label} должно содержать обычный текст без HTML.");
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
            'exists' => 'Связанная запись не найдена.',
            'enum' => 'Для поля «:attribute» выбрано недопустимое значение.',
        ];
    }

    private function authorize(User $actor, string $ability, mixed $target): void
    {
        if (! $actor->can($ability, $target)) {
            throw new AuthorizationException('Недостаточно прав для управления статическим содержимым сайта.');
        }
    }
}
