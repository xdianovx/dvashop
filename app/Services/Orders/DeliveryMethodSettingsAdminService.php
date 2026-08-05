<?php

namespace App\Services\Orders;

use App\Enums\DeliveryMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryMethodSettingsAdminService
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'code',
        'title',
        'description',
        'base_price',
        'is_active',
        'position',
    ];

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, DeliveryMethodSetting $setting, array $attributes): DeliveryMethodSetting
    {
        $this->authorize($actor, 'update', $setting);

        return DB::transaction(function () use ($setting, $attributes): DeliveryMethodSetting {
            $locked = DeliveryMethodSetting::query()->whereKey($setting)->lockForUpdate()->firstOrFail();
            $validated = $this->validatedAttributes($attributes, $locked);
            $locked->fill($validated)->save();

            return $locked->refresh();
        });
    }

    public function setActive(User $actor, DeliveryMethodSetting $setting, bool $active): DeliveryMethodSetting
    {
        $this->authorize($actor, 'update', $setting);

        return DB::transaction(function () use ($setting, $active): DeliveryMethodSetting {
            $locked = DeliveryMethodSetting::query()->whereKey($setting)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => $active])->save();

            return $locked->refresh();
        });
    }

    /** @param array<int|string, mixed> $ids */
    public function reorder(User $actor, array $ids): void
    {
        $this->authorize($actor, 'reorder', DeliveryMethodSetting::class);
        $validatedIds = $this->validatedIds($ids);

        DB::transaction(function () use ($validatedIds): void {
            $settings = DeliveryMethodSetting::query()->orderBy('id')->lockForUpdate()->get();

            if ($settings->pluck('id')->sort()->values()->all() !== $validatedIds->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ids' => 'Сортировка должна содержать все существующие способы доставки без посторонних записей.',
                ]);
            }

            foreach ($validatedIds as $position => $id) {
                $setting = $settings->firstWhere('id', $id);

                if (! $setting instanceof DeliveryMethodSetting) {
                    throw ValidationException::withMessages([
                        'ids' => 'Один из способов доставки не существует.',
                    ]);
                }

                $setting->forceFill(['position' => $position])->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedAttributes(array $attributes, DeliveryMethodSetting $existing): array
    {
        $unexpected = array_values(array_diff(array_keys($attributes), self::EDITABLE_FIELDS));

        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [$field => "Поле «{$field}» нельзя изменять в способе доставки."])
                ->all());
        }

        $candidate = array_merge($existing->only(self::EDITABLE_FIELDS), $attributes);
        $candidate['code'] = $candidate['code'] instanceof DeliveryMethod
            ? $candidate['code']->value
            : $candidate['code'];

        foreach (['title', 'description'] as $field) {
            if (is_string($candidate[$field] ?? null)) {
                $candidate[$field] = trim($candidate[$field]);
            }
        }

        if (($candidate['description'] ?? null) === '') {
            $candidate['description'] = null;
        }

        if (($candidate['code'] ?? null) !== $existing->code->value) {
            throw ValidationException::withMessages([
                'code' => 'Системный код способа доставки нельзя изменять.',
            ]);
        }

        $plainText = function (string $attribute, mixed $value, callable $fail): void {
            if (is_string($value) && strip_tags($value) !== $value) {
                $fail('Поле должно содержать обычный текст без HTML.');
            }
        };

        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(DeliveryMethod::class)],
            'title' => ['required', 'string', 'max:255', $plainText],
            'description' => ['nullable', 'string', 'max:5000', $plainText],
            'base_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], [
            'required' => 'Поле «:attribute» обязательно.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'numeric' => 'Поле «:attribute» должно быть числом.',
            'decimal' => 'Стоимость должна содержать не более двух знаков после точки.',
            'integer' => 'Поле «:attribute» должно быть целым числом.',
            'min' => 'Поле «:attribute» не может быть отрицательным.',
            'boolean' => 'Поле «:attribute» должно быть логическим значением.',
            'enum' => 'Выбран неизвестный способ доставки.',
        ], [
            'code' => 'код',
            'title' => 'название',
            'description' => 'описание',
            'base_price' => 'базовая стоимость',
            'is_active' => 'активность',
            'position' => 'позиция',
        ])->validate();

        $validated['base_price'] = number_format((float) $validated['base_price'], 2, '.', '');
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<int|string, mixed> $ids */
    private function validatedIds(array $ids): Collection
    {
        $validated = Validator::make(['ids' => $ids], [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'ids.required' => 'Передайте способы доставки для сортировки.',
            'ids.array' => 'Список способов доставки имеет неверный формат.',
            'ids.min' => 'Список способов доставки не может быть пустым.',
            'ids.*.integer' => 'Идентификаторы способов доставки должны быть целыми числами.',
            'ids.*.min' => 'Идентификаторы способов доставки должны быть положительными.',
            'ids.*.distinct' => 'Список способов доставки содержит повторяющиеся записи.',
        ])->validate();

        return collect($validated['ids'])->map(fn (mixed $id): int => (int) $id)->values();
    }

    private function authorize(User $actor, string $ability, mixed $target): void
    {
        if (! $actor->can($ability, $target)) {
            throw new AuthorizationException('Недостаточно прав для управления способами доставки.');
        }
    }
}
