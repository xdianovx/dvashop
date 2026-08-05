<?php

namespace App\Services\Orders;

use App\Enums\PaymentMethod;
use App\Models\PaymentMethodSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentMethodSettingsAdminService
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'code',
        'title',
        'description',
        'is_active',
        'position',
    ];

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, PaymentMethodSetting $setting, array $attributes): PaymentMethodSetting
    {
        $this->authorize($actor, 'update', $setting);

        return DB::transaction(function () use ($setting, $attributes): PaymentMethodSetting {
            $locked = PaymentMethodSetting::query()->whereKey($setting)->lockForUpdate()->firstOrFail();
            $validated = $this->validatedAttributes($attributes, $locked);
            $locked->fill($validated)->save();

            return $locked->refresh();
        });
    }

    public function setActive(User $actor, PaymentMethodSetting $setting, bool $active): PaymentMethodSetting
    {
        $this->authorize($actor, 'update', $setting);

        return DB::transaction(function () use ($setting, $active): PaymentMethodSetting {
            $locked = PaymentMethodSetting::query()->whereKey($setting)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => $active])->save();

            return $locked->refresh();
        });
    }

    /** @param array<int|string, mixed> $ids */
    public function reorder(User $actor, array $ids): void
    {
        $this->authorize($actor, 'reorder', PaymentMethodSetting::class);
        $validatedIds = $this->validatedIds($ids);

        DB::transaction(function () use ($validatedIds): void {
            $settings = PaymentMethodSetting::query()->orderBy('id')->lockForUpdate()->get();

            if ($settings->pluck('id')->sort()->values()->all() !== $validatedIds->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ids' => 'Сортировка должна содержать все существующие способы оплаты без посторонних записей.',
                ]);
            }

            foreach ($validatedIds as $position => $id) {
                $setting = $settings->firstWhere('id', $id);

                if (! $setting instanceof PaymentMethodSetting) {
                    throw ValidationException::withMessages([
                        'ids' => 'Один из способов оплаты не существует.',
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
    private function validatedAttributes(array $attributes, PaymentMethodSetting $existing): array
    {
        $unexpected = array_values(array_diff(array_keys($attributes), self::EDITABLE_FIELDS));

        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [$field => "Поле «{$field}» нельзя изменять в способе оплаты."])
                ->all());
        }

        $candidate = array_merge($existing->only(self::EDITABLE_FIELDS), $attributes);
        $candidate['code'] = $candidate['code'] instanceof PaymentMethod
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
                'code' => 'Системный код способа оплаты нельзя изменять.',
            ]);
        }

        $plainText = function (string $attribute, mixed $value, callable $fail): void {
            if (is_string($value) && strip_tags($value) !== $value) {
                $fail('Поле должно содержать обычный текст без HTML.');
            }
        };

        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(PaymentMethod::class)],
            'title' => ['required', 'string', 'max:255', $plainText],
            'description' => ['nullable', 'string', 'max:5000', $plainText],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], [
            'required' => 'Поле «:attribute» обязательно.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'integer' => 'Поле «:attribute» должно быть целым числом.',
            'min' => 'Поле «:attribute» не может быть отрицательным.',
            'boolean' => 'Поле «:attribute» должно быть логическим значением.',
            'enum' => 'Выбран неизвестный способ оплаты.',
        ], [
            'code' => 'код',
            'title' => 'название',
            'description' => 'описание',
            'is_active' => 'активность',
            'position' => 'позиция',
        ])->validate();

        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return Collection<int, int>
     */
    private function validatedIds(array $ids): Collection
    {
        $validated = Validator::make(['ids' => $ids], [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'ids.required' => 'Передайте способы оплаты для сортировки.',
            'ids.array' => 'Список способов оплаты имеет неверный формат.',
            'ids.min' => 'Список способов оплаты не может быть пустым.',
            'ids.*.integer' => 'Идентификаторы способов оплаты должны быть целыми числами.',
            'ids.*.min' => 'Идентификаторы способов оплаты должны быть положительными.',
            'ids.*.distinct' => 'Список способов оплаты содержит повторяющиеся записи.',
        ])->validate();

        return collect($validated['ids'])->map(fn (mixed $id): int => (int) $id)->values();
    }

    private function authorize(User $actor, string $ability, mixed $target): void
    {
        if (! $actor->can($ability, $target)) {
            throw new AuthorizationException('Недостаточно прав для управления способами оплаты.');
        }
    }
}
