<?php

namespace App\Services\Settings;

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use App\Models\SiteNavigationItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteNavigationAdminService
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'code',
        'zone',
        'title',
        'link_type',
        'route_name',
        'url',
        'open_in_new_tab',
        'is_active',
        'position',
    ];

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): SiteNavigationItem
    {
        $this->authorize($actor, 'create', SiteNavigationItem::class);

        return DB::transaction(function () use ($attributes): SiteNavigationItem {
            $validated = $this->validatedAttributes($attributes);

            try {
                return SiteNavigationItem::query()->create($validated);
            } catch (QueryException $exception) {
                $this->translateDuplicateCode($validated['code'], $exception);
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, SiteNavigationItem $item, array $attributes): SiteNavigationItem
    {
        $this->authorize($actor, 'update', $item);

        return DB::transaction(function () use ($item, $attributes): SiteNavigationItem {
            $locked = SiteNavigationItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();
            $validated = $this->validatedAttributes($attributes, $locked);

            try {
                $locked->fill($validated)->save();
            } catch (QueryException $exception) {
                $this->translateDuplicateCode($validated['code'], $exception, $locked);
            }

            return $locked->refresh();
        });
    }

    public function setActive(User $actor, SiteNavigationItem $item, bool $active): SiteNavigationItem
    {
        $this->authorize($actor, 'update', $item);

        return DB::transaction(function () use ($item, $active): SiteNavigationItem {
            $locked = SiteNavigationItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => $active])->save();

            return $locked->refresh();
        });
    }

    public function delete(User $actor, SiteNavigationItem $item): void
    {
        $this->authorize($actor, 'delete', $item);

        DB::transaction(function () use ($item): void {
            $locked = SiteNavigationItem::query()->whereKey($item)->lockForUpdate()->firstOrFail();
            $locked->delete();
        });
    }

    /** @param array<int|string, mixed> $ids */
    public function reorder(User $actor, mixed $zone, array $ids): void
    {
        $this->authorize($actor, 'reorder', SiteNavigationItem::class);

        $validated = Validator::make([
            'zone' => $zone instanceof NavigationZone ? $zone->value : $zone,
            'ids' => $ids,
        ], [
            'zone' => ['required', Rule::enum(NavigationZone::class)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'zone.required' => 'Укажите зону навигации для сортировки.',
            'zone.enum' => 'Выбрана недопустимая зона навигации.',
            'ids.required' => 'Передайте пункты навигации для сортировки.',
            'ids.array' => 'Список пунктов навигации имеет неверный формат.',
            'ids.min' => 'Список пунктов навигации не может быть пустым.',
            'ids.*.integer' => 'Идентификаторы пунктов навигации должны быть целыми числами.',
            'ids.*.min' => 'Идентификаторы пунктов навигации должны быть положительными.',
            'ids.*.distinct' => 'Список пунктов навигации содержит повторяющиеся записи.',
        ])->validate();

        DB::transaction(function () use ($validated): void {
            $zone = NavigationZone::from($validated['zone']);
            $requestedIds = collect($validated['ids'])->map(fn (mixed $id): int => (int) $id)->values();
            $items = SiteNavigationItem::query()
                ->where('zone', $zone->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->pluck('id')->sort()->values()->all() !== $requestedIds->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ids' => 'Сортировка должна содержать все существующие пункты только одной выбранной зоны.',
                ]);
            }

            foreach ($requestedIds as $position => $id) {
                $item = $items->firstWhere('id', $id);

                if (! $item instanceof SiteNavigationItem) {
                    throw ValidationException::withMessages([
                        'ids' => 'Один из пунктов навигации не существует или принадлежит другой зоне.',
                    ]);
                }

                $item->forceFill(['position' => $position])->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedAttributes(array $attributes, ?SiteNavigationItem $existing = null): array
    {
        $unexpected = array_values(array_diff(array_keys($attributes), self::EDITABLE_FIELDS));

        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [$field => "Поле «{$field}» нельзя сохранять в пункте навигации."])
                ->all());
        }

        $candidate = array_merge($existing?->only(self::EDITABLE_FIELDS) ?? [
            'route_name' => null,
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => true,
            'position' => 0,
        ], $attributes);

        if (($candidate['zone'] ?? null) instanceof NavigationZone) {
            $candidate['zone'] = $candidate['zone']->value;
        }

        if (($candidate['link_type'] ?? null) instanceof NavigationLinkType) {
            $candidate['link_type'] = $candidate['link_type']->value;
        }

        if (array_key_exists('link_type', $attributes)) {
            if (($candidate['link_type'] ?? null) === NavigationLinkType::Route->value
                && ! array_key_exists('url', $attributes)) {
                $candidate['url'] = null;
            }

            if (($candidate['link_type'] ?? null) === NavigationLinkType::Url->value
                && ! array_key_exists('route_name', $attributes)) {
                $candidate['route_name'] = null;
            }
        }

        foreach (['code', 'zone', 'title', 'link_type', 'route_name', 'url'] as $field) {
            if (is_string($candidate[$field] ?? null)) {
                $candidate[$field] = trim($candidate[$field]);
            }
        }

        if (is_string($candidate['code'] ?? null)) {
            $candidate['code'] = mb_strtolower($candidate['code']);
        }

        foreach (['route_name', 'url'] as $field) {
            if (($candidate[$field] ?? null) === '') {
                $candidate[$field] = null;
            }
        }

        if ($existing instanceof SiteNavigationItem
            && ($candidate['code'] ?? null) !== $existing->code) {
            throw ValidationException::withMessages([
                'code' => 'Стабильный код существующего пункта навигации нельзя изменять.',
            ]);
        }

        $httpUrl = function (string $attribute, mixed $value, callable $fail): void {
            if ($value === null) {
                return;
            }

            if (! is_string($value)
                || filter_var($value, FILTER_VALIDATE_URL) === false
                || ! in_array(mb_strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                || blank(parse_url($value, PHP_URL_HOST))) {
                $fail('URL должен быть абсолютным и использовать протокол http или https.');
            }
        };

        $validated = Validator::make($candidate, [
            'code' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('site_navigation_items', 'code')->ignore($existing?->getKey()),
            ],
            'zone' => ['required', Rule::enum(NavigationZone::class)],
            'title' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, callable $fail): void {
                if (is_string($value) && strip_tags($value) !== $value) {
                    $fail('Название пункта навигации должно быть обычным текстом без HTML.');
                }
            }],
            'link_type' => ['required', Rule::enum(NavigationLinkType::class)],
            'route_name' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255', $httpUrl],
            'open_in_new_tab' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], [
            'required' => 'Поле «:attribute» обязательно.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'integer' => 'Поле «:attribute» должно быть целым числом.',
            'min' => 'Поле «:attribute» должно быть не меньше :min.',
            'boolean' => 'Поле «:attribute» должно быть логическим значением.',
            'enum' => 'Для поля «:attribute» выбрано недопустимое значение.',
            'code.regex' => 'Код может содержать только строчные латинские буквы, цифры, подчёркивание и дефис.',
            'code.unique' => 'Пункт навигации с таким кодом уже существует.',
        ], [
            'code' => 'код',
            'zone' => 'зона',
            'title' => 'название',
            'link_type' => 'тип ссылки',
            'route_name' => 'имя маршрута',
            'url' => 'URL',
            'open_in_new_tab' => 'открытие в новой вкладке',
            'is_active' => 'активность',
            'position' => 'позиция',
        ])->validate();

        if ($validated['link_type'] === NavigationLinkType::Route->value) {
            if (blank($validated['route_name'])) {
                throw ValidationException::withMessages([
                    'route_name' => 'Для ссылки на маршрут необходимо указать имя маршрута.',
                ]);
            }

            if (! Route::has($validated['route_name'])) {
                throw ValidationException::withMessages([
                    'route_name' => 'Указанный маршрут сайта не существует.',
                ]);
            }

            if (($validated['url'] ?? null) !== null) {
                throw ValidationException::withMessages([
                    'url' => 'Для ссылки на маршрут поле URL должно быть пустым.',
                ]);
            }
        } else {
            if (($validated['url'] ?? null) === null) {
                throw ValidationException::withMessages([
                    'url' => 'Для внешней ссылки необходимо указать URL.',
                ]);
            }

            if (($validated['route_name'] ?? null) !== null) {
                throw ValidationException::withMessages([
                    'route_name' => 'Для внешней ссылки имя маршрута должно быть пустым.',
                ]);
            }
        }

        $validated['position'] = (int) $validated['position'];
        $validated['open_in_new_tab'] = (bool) $validated['open_in_new_tab'];
        $validated['is_active'] = (bool) $validated['is_active'];

        return $validated;
    }

    private function authorize(User $actor, string $ability, mixed $target): void
    {
        if (! $actor->can($ability, $target)) {
            throw new AuthorizationException('Недостаточно прав для управления навигацией сайта.');
        }
    }

    private function translateDuplicateCode(
        string $code,
        QueryException $exception,
        ?SiteNavigationItem $existing = null,
    ): never {
        $duplicate = SiteNavigationItem::query()
            ->where('code', $code)
            ->when($existing instanceof SiteNavigationItem, fn ($query) => $query->whereKeyNot($existing->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'code' => 'Пункт навигации с таким кодом уже существует.',
            ]);
        }

        throw $exception;
    }
}
