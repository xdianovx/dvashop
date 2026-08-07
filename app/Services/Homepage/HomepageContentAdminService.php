<?php

namespace App\Services\Homepage;

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageQuickLinkCode;
use App\Enums\HomepageSectionCode;
use App\Enums\NavigationLinkType;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageQuickLink;
use App\Models\HomepageSection;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HomepageContentAdminService
{
    private const DESTINATION_FIELDS = [
        'code',
        'title',
        'link_type',
        'route_name',
        'url',
        'open_in_new_tab',
        'is_active',
        'position',
    ];

    /** @param array<string, mixed> $attributes */
    public function updateSection(User $actor, HomepageSection $section, array $attributes): HomepageSection
    {
        /** @var HomepageSection $updated */
        $updated = $this->updateRecord(
            $actor,
            $section,
            $attributes,
            fn (array $data, Model $record): array => $this->validateSection($data, $record),
        );

        return $updated;
    }

    public function setSectionActive(User $actor, HomepageSection $section, bool $active): HomepageSection
    {
        /** @var HomepageSection $updated */
        $updated = $this->setActive($actor, $section, $active);

        return $updated;
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderSections(User $actor, array $ids): void
    {
        $this->reorder($actor, HomepageSection::class, $ids, 'секции главной страницы');
    }

    /** @param array<string, mixed> $attributes */
    public function updateQuickLink(User $actor, HomepageQuickLink $link, array $attributes): HomepageQuickLink
    {
        /** @var HomepageQuickLink $updated */
        $updated = $this->updateRecord(
            $actor,
            $link,
            $attributes,
            fn (array $data, Model $record): array => $this->validateDestination(
                $data,
                $record,
                HomepageQuickLinkCode::class,
                'быстрой ссылки',
            ),
        );

        return $updated;
    }

    public function setQuickLinkActive(User $actor, HomepageQuickLink $link, bool $active): HomepageQuickLink
    {
        return $this->updateQuickLink($actor, $link, ['is_active' => $active]);
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderQuickLinks(User $actor, array $ids): void
    {
        $this->reorder($actor, HomepageQuickLink::class, $ids, 'быстрые ссылки');
    }

    /** @param array<string, mixed> $attributes */
    public function updateCategoryCard(User $actor, HomepageCategoryCard $card, array $attributes): HomepageCategoryCard
    {
        /** @var HomepageCategoryCard $updated */
        $updated = $this->updateRecord(
            $actor,
            $card,
            $attributes,
            fn (array $data, Model $record): array => $this->validateCategoryCard($data, $record),
        );

        return $updated;
    }

    public function setCategoryCardActive(User $actor, HomepageCategoryCard $card, bool $active): HomepageCategoryCard
    {
        return $this->updateCategoryCard($actor, $card, ['is_active' => $active]);
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderCategoryCards(User $actor, array $ids): void
    {
        $this->reorder($actor, HomepageCategoryCard::class, $ids, 'карточки категорий');
    }

    /** @param array<string, mixed> $attributes */
    public function updateMetric(User $actor, HomepageMetric $metric, array $attributes): HomepageMetric
    {
        /** @var HomepageMetric $updated */
        $updated = $this->updateRecord(
            $actor,
            $metric,
            $attributes,
            fn (array $data, Model $record): array => $this->validateMetric($data, $record),
        );

        return $updated;
    }

    public function setMetricActive(User $actor, HomepageMetric $metric, bool $active): HomepageMetric
    {
        /** @var HomepageMetric $updated */
        $updated = $this->setActive($actor, $metric, $active);

        return $updated;
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderMetrics(User $actor, array $ids): void
    {
        $this->reorder($actor, HomepageMetric::class, $ids, 'показатели главной страницы');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Closure(array<string, mixed>, Model): array<string, mixed>  $validator
     */
    private function updateRecord(
        User $actor,
        Model $record,
        array $attributes,
        Closure $validator,
    ): Model {
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

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int|string, mixed>  $ids
     */
    private function reorder(User $actor, string $modelClass, array $ids, string $label): void
    {
        $this->authorize($actor, 'reorder', $modelClass);
        $validatedIds = $this->validateReorderIds($ids, $label);

        DB::transaction(function () use ($modelClass, $validatedIds, $label): void {
            $records = $modelClass::query()->orderBy('id')->lockForUpdate()->get();

            if ($records->pluck('id')->sort()->values()->all() !== collect($validatedIds)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ids' => "Сортировка должна содержать все существующие {$label} без пропусков и посторонних записей.",
                ]);
            }

            foreach ($validatedIds as $position => $id) {
                $modelClass::query()->whereKey($id)->update(['position' => $position]);
            }
        });
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
     * @return array<string, mixed>
     */
    private function validateSection(array $attributes, Model $record): array
    {
        $fields = ['code', 'title', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'секции главной страницы');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);
        $candidate['title'] = $this->trimNullable($candidate['title'] ?? null);
        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'секции');

        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(HomepageSectionCode::class)],
            'title' => ['nullable', 'string', 'max:255', $this->plainTextRule('Название секции')],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  class-string<\BackedEnum>  $codeEnum
     * @return array<string, mixed>
     */
    private function validateDestination(array $attributes, Model $record, string $codeEnum, string $label): array
    {
        $this->rejectUnexpected($attributes, self::DESTINATION_FIELDS, $label);
        $candidate = array_merge($record->only(self::DESTINATION_FIELDS), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);
        $candidate['link_type'] = $this->backedValue($candidate['link_type'] ?? null);

        foreach (['title', 'link_type', 'route_name', 'url'] as $field) {
            $candidate[$field] = $this->trimNullable($candidate[$field] ?? null);
        }

        if (array_key_exists('link_type', $attributes)) {
            if ($candidate['link_type'] === null) {
                if (! array_key_exists('route_name', $attributes)) {
                    $candidate['route_name'] = null;
                }
                if (! array_key_exists('url', $attributes)) {
                    $candidate['url'] = null;
                }
            } elseif ($candidate['link_type'] === NavigationLinkType::Route->value
                && ! array_key_exists('url', $attributes)) {
                $candidate['url'] = null;
            } elseif ($candidate['link_type'] === NavigationLinkType::Url->value
                && ! array_key_exists('route_name', $attributes)) {
                $candidate['route_name'] = null;
            }
        }

        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, $label);

        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum($codeEnum)],
            'title' => ['required', 'string', 'max:255', $this->plainTextRule('Название')],
            'link_type' => ['nullable', Rule::enum(NavigationLinkType::class)],
            'route_name' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'open_in_new_tab' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        $this->validateDestinationCombination($validated);
        $validated['open_in_new_tab'] = (bool) $validated['open_in_new_tab'];
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        if (($validated['link_type'] ?? null) === null) {
            $validated['open_in_new_tab'] = false;
            $validated['is_active'] = false;
        }

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateCategoryCard(array $attributes, Model $record): array
    {
        $fields = [
            'code',
            'title',
            'link_type',
            'route_name',
            'product_category_id',
            'part_type_id',
            'url',
            'open_in_new_tab',
            'is_active',
            'position',
        ];
        $this->rejectUnexpected($attributes, $fields, 'карточки категории');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);
        $candidate['link_type'] = $this->backedValue($candidate['link_type'] ?? null);
        $candidate['title'] = $this->trimNullable($candidate['title'] ?? null);
        $candidate['route_name'] = $this->trimNullable($candidate['route_name'] ?? null);
        $candidate['url'] = $this->trimNullable($candidate['url'] ?? null);
        $candidate['product_category_id'] = $this->nullablePositiveId($candidate['product_category_id'] ?? null, 'product_category_id');
        $candidate['part_type_id'] = $this->nullablePositiveId($candidate['part_type_id'] ?? null, 'part_type_id');
        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'карточки категории');

        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(HomepageCategoryCardCode::class)],
            'title' => ['required', 'string', 'max:255', $this->plainTextRule('Название')],
            'link_type' => ['nullable', Rule::enum(NavigationLinkType::class)],
            'route_name' => ['nullable', 'string', 'max:255'],
            'product_category_id' => ['nullable', 'integer', 'min:1'],
            'part_type_id' => ['nullable', 'integer', 'min:1'],
            'url' => ['nullable', 'string', 'max:2048'],
            'open_in_new_tab' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        if ($validated['product_category_id'] !== null && $validated['part_type_id'] !== null) {
            throw ValidationException::withMessages([
                'product_category_id' => 'Карточка не может одновременно вести в категорию магазина и тип детали.',
            ]);
        }

        if (($validated['url'] ?? null) !== null || ($validated['link_type'] ?? null) === NavigationLinkType::Url->value) {
            throw ValidationException::withMessages(['url' => 'Внешние ссылки для витринных карточек не поддерживаются.']);
        }

        $hasRelation = $validated['product_category_id'] !== null || $validated['part_type_id'] !== null;
        if ($hasRelation && (($validated['link_type'] ?? null) !== null || ($validated['route_name'] ?? null) !== null)) {
            throw ValidationException::withMessages(['route_name' => 'Каталожная связь не может сочетаться с маршрутом.']);
        }

        if (($validated['link_type'] ?? null) !== null || ($validated['route_name'] ?? null) !== null) {
            if (($validated['link_type'] ?? null) !== NavigationLinkType::Route->value
                || ($validated['route_name'] ?? null) !== 'catalog.index'
                || ! Route::has('catalog.index')) {
                throw ValidationException::withMessages(['route_name' => 'Для витринной карточки разрешён только существующий маршрут всего каталога.']);
            }
        }

        if ($validated['product_category_id'] !== null) {
            $category = ProductCategory::withTrashed()->find($validated['product_category_id']);
            if (! $category instanceof ProductCategory) {
                throw ValidationException::withMessages(['product_category_id' => 'Категория магазина не найдена.']);
            }
            $same = (int) $record->getRawOriginal('product_category_id') === (int) $category->getKey();
            if (($category->trashed() || ! $category->is_active) && ! $same) {
                throw ValidationException::withMessages(['product_category_id' => 'Нельзя назначить неактивную или удалённую категорию магазина.']);
            }
            if ($category->trashed() || ! $category->is_active) {
                $validated['is_active'] = false;
            }
        }

        if ($validated['part_type_id'] !== null) {
            $partType = PartType::withTrashed()->find($validated['part_type_id']);
            if (! $partType instanceof PartType) {
                throw ValidationException::withMessages(['part_type_id' => 'Тип детали не найден.']);
            }
            $same = (int) $record->getRawOriginal('part_type_id') === (int) $partType->getKey();
            if (($partType->trashed() || ! $partType->is_active) && ! $same) {
                throw ValidationException::withMessages(['part_type_id' => 'Нельзя назначить неактивный или удалённый тип детали.']);
            }
            if ($partType->trashed() || ! $partType->is_active) {
                $validated['is_active'] = false;
            }
        }

        $hasDestination = $hasRelation
            || (($validated['link_type'] ?? null) === NavigationLinkType::Route->value
                && ($validated['route_name'] ?? null) === 'catalog.index');

        if (! $hasDestination) {
            $validated['link_type'] = null;
            $validated['route_name'] = null;
            $validated['is_active'] = false;
        }

        $validated['url'] = null;
        $validated['open_in_new_tab'] = false;
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateMetric(array $attributes, Model $record): array
    {
        $fields = ['code', 'prefix', 'value', 'suffix', 'text', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'показателя главной страницы');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);

        foreach (['prefix', 'value', 'suffix', 'text'] as $field) {
            $candidate[$field] = $this->trimNullable($candidate[$field] ?? null);
        }

        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'показателя');
        $plainText = $this->plainTextRule('Текстовое поле');
        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(HomepageMetricCode::class)],
            'prefix' => ['nullable', 'string', 'max:32', $plainText],
            'value' => ['required', 'string', 'max:64', $plainText],
            'suffix' => ['nullable', 'string', 'max:64', $plainText],
            'text' => ['required', 'string', 'max:500', $plainText],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<string, mixed> $destination */
    private function validateDestinationCombination(array $destination): void
    {
        $type = $destination['link_type'] ?? null;
        $routeName = $destination['route_name'] ?? null;
        $url = $destination['url'] ?? null;

        if ($type === null) {
            if ($routeName !== null || $url !== null) {
                throw ValidationException::withMessages([
                    'link_type' => 'Без типа перехода имя маршрута и URL должны быть пустыми.',
                ]);
            }

            return;
        }

        if ($type === NavigationLinkType::Route->value) {
            if ($routeName === null) {
                throw ValidationException::withMessages(['route_name' => 'Для перехода по маршруту укажите имя маршрута.']);
            }
            if (! Route::has($routeName)) {
                throw ValidationException::withMessages(['route_name' => 'Указанный маршрут сайта не существует.']);
            }
            if ($url !== null) {
                throw ValidationException::withMessages(['url' => 'Для перехода по маршруту URL должен быть пустым.']);
            }

            return;
        }

        if ($url === null) {
            throw ValidationException::withMessages(['url' => 'Для внешней ссылки укажите URL.']);
        }
        if ($routeName !== null) {
            throw ValidationException::withMessages(['route_name' => 'Для внешней ссылки имя маршрута должно быть пустым.']);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || blank(parse_url($url, PHP_URL_HOST))) {
            throw ValidationException::withMessages(['url' => 'URL должен быть абсолютным и использовать протокол http или https.']);
        }
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

    private function nullablePositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw ValidationException::withMessages([$field => 'Выберите существующую запись.']);
    }

    private function plainTextRule(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            if (is_string($value) && strip_tags($value) !== $value) {
                $fail("{$label} должно содержать обычный текст без HTML.");
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
            'min' => 'Поле «:attribute» не может быть отрицательным.',
            'boolean' => 'Поле «:attribute» должно быть логическим значением.',
            'enum' => 'Для поля «:attribute» выбрано недопустимое значение.',
        ];
    }

    private function authorize(User $actor, string $ability, mixed $target): void
    {
        if (! $actor->can($ability, $target)) {
            throw new AuthorizationException('Недостаточно прав для управления содержимым главной страницы.');
        }
    }
}
