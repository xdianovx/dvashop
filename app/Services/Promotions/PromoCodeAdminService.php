<?php

namespace App\Services\Promotions;

use App\Enums\AdminPermission;
use App\Enums\PromoDiscountType;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PromoCodeAdminService
{
    /** @var list<string> */
    private const FIELDS = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'minimum_eligible_subtotal',
        'applies_to_all',
        'allow_sale_items',
        'usage_limit',
        'starts_at',
        'ends_at',
        'is_active',
        'product_ids',
        'product_category_ids',
        'part_type_ids',
    ];

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): PromoCode
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data): PromoCode {
            [$attributes, $targets] = $this->validated($data);
            $promo = PromoCode::query()->create($attributes);
            $this->syncTargets($promo, $targets);

            return $promo->refresh()->load(['products', 'productCategories', 'partTypes']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, PromoCode $promo, array $data): PromoCode
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($promo, $data): PromoCode {
            $locked = PromoCode::withTrashed()->whereKey($promo)->lockForUpdate()->firstOrFail();
            [$attributes, $targets] = $this->validated($data, $locked);

            if ($locked->redemptions()->exists() && $attributes['code'] !== $locked->code) {
                throw ValidationException::withMessages([
                    'code' => 'Нельзя изменить код промокода после первого использования.',
                ]);
            }

            $locked->update($attributes);
            $this->syncTargets($locked, $targets);

            return $locked->refresh()->load(['products', 'productCategories', 'partTypes']);
        });
    }

    public function archive(User $actor, PromoCode $promo): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($promo): void {
            PromoCode::query()->whereKey($promo)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    public function restore(User $actor, PromoCode $promo): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($promo): void {
            PromoCode::onlyTrashed()->whereKey($promo)->lockForUpdate()->firstOrFail()->restore();
        });
    }

    public function generateUniqueCode(string $prefix = 'SALE'): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $suffix = collect(range(1, 6))
                ->map(fn (): string => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');
            $code = PromoCode::normalizeCode(Str::limit($prefix, 16, '').'-'.$suffix);
        } while (PromoCode::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array{products: list<int>, categories: list<int>, part_types: list<int>}}
     */
    private function validated(array $data, ?PromoCode $promo = null): array
    {
        $unexpected = array_values(array_diff(array_keys($data), self::FIELDS));

        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [$field => "Поле «{$field}» нельзя изменять."])
                ->all());
        }

        $data = Arr::only($data, self::FIELDS);
        $data['code'] = PromoCode::normalizeCode($data['code'] ?? '');
        $data['description'] = $this->nullableTrimmed($data['description'] ?? null);
        $data['max_discount_amount'] = $this->nullableValue($data['max_discount_amount'] ?? null);
        $data['minimum_eligible_subtotal'] = $this->nullableValue($data['minimum_eligible_subtotal'] ?? null);
        $data['usage_limit'] = $this->nullableValue($data['usage_limit'] ?? null);
        $data['starts_at'] = $this->nullableValue($data['starts_at'] ?? null);
        $data['ends_at'] = $this->nullableValue($data['ends_at'] ?? null);
        $data['product_ids'] = $this->ids($data['product_ids'] ?? []);
        $data['product_category_ids'] = $this->ids($data['product_category_ids'] ?? []);
        $data['part_type_ids'] = $this->ids($data['part_type_ids'] ?? []);

        $validated = Validator::make($data, [
            'code' => [
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/\A[A-Z0-9_-]+\z/',
                Rule::unique('promo_codes', 'code')->ignore($promo),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'discount_type' => ['required', Rule::enum(PromoDiscountType::class)],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'minimum_eligible_subtotal' => ['nullable', 'numeric', 'gte:0'],
            'applies_to_all' => ['required', 'boolean'],
            'allow_sale_items' => ['required', 'boolean'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['required', 'boolean'],
            'product_ids' => ['array'],
            'product_ids.*' => ['integer', 'distinct'],
            'product_category_ids' => ['array'],
            'product_category_ids.*' => ['integer', 'distinct'],
            'part_type_ids' => ['array'],
            'part_type_ids.*' => ['integer', 'distinct'],
        ], [
            'code.regex' => 'Код может содержать только латинские буквы, цифры, дефис и подчёркивание.',
            'code.unique' => 'Промокод с таким кодом уже существует.',
            'ends_at.after_or_equal' => 'Дата окончания не может быть раньше даты начала.',
        ])->validate();

        $type = PromoDiscountType::from($validated['discount_type']);

        if ($type === PromoDiscountType::Percentage && (float) $validated['discount_value'] > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Процентная скидка не может превышать 100%.',
            ]);
        }

        if ($type === PromoDiscountType::Fixed) {
            $fixedAmountIsCentExact = Validator::make(
                ['discount_value' => $validated['discount_value']],
                ['discount_value' => ['decimal:0,2']],
            )->passes();

            if (! $fixedAmountIsCentExact) {
                throw ValidationException::withMessages([
                    'discount_value' => 'Фиксированная скидка должна быть указана с точностью не более двух знаков после запятой.',
                ]);
            }

            $validated['max_discount_amount'] = null;
        }

        $targets = [
            'products' => $validated['product_ids'],
            'categories' => $validated['product_category_ids'],
            'part_types' => $validated['part_type_ids'],
        ];

        if (! $validated['applies_to_all'] && collect($targets)->flatten()->isEmpty()) {
            throw ValidationException::withMessages([
                'product_ids' => 'Выберите хотя бы один товар, категорию или тип детали.',
            ]);
        }

        $this->assertExistingTargets(Product::class, $targets['products'], 'product_ids');
        $this->assertExistingTargets(ProductCategory::class, $targets['categories'], 'product_category_ids');
        $this->assertExistingTargets(PartType::class, $targets['part_types'], 'part_type_ids');

        $attributes = Arr::except($validated, ['product_ids', 'product_category_ids', 'part_type_ids']);
        $attributes['name'] = trim($attributes['name']);

        return [$attributes, $targets];
    }

    /** @param array{products: list<int>, categories: list<int>, part_types: list<int>} $targets */
    private function syncTargets(PromoCode $promo, array $targets): void
    {
        if ($promo->applies_to_all) {
            $targets = ['products' => [], 'categories' => [], 'part_types' => []];
        }

        $promo->products()->sync($targets['products']);
        $promo->productCategories()->sync($targets['categories']);
        $promo->partTypes()->sync($targets['part_types']);
    }

    /** @param class-string<Product|ProductCategory|PartType> $model @param list<int> $ids */
    private function assertExistingTargets(string $model, array $ids, string $field): void
    {
        if ($ids === []) {
            return;
        }

        if ($model::query()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $field => 'Один или несколько выбранных объектов недоступны.',
            ]);
        }
    }

    /** @return list<int> */
    private function ids(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private function nullableValue(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->canPerformAdminAction(AdminPermission::ManagePromoCodes)) {
            throw new AuthorizationException('Недостаточно прав для управления промокодами.');
        }
    }
}
