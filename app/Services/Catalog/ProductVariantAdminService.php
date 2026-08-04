<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductVariantAdminService
{
    public function __construct(
        private readonly KnownUniqueConstraintGuard $uniqueConstraints,
        private readonly CatalogRelationIdNormalizer $relationIds,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(ProductVariant $variant, array $data): ProductVariant
    {
        return DB::transaction(fn (): ProductVariant => $this->guardVariantSkuSave(
            function () use ($variant, $data): ProductVariant {
                $variant->fill($data)->save();

                return $variant->refresh();
            },
        ));
    }

    /** @template TResult @param Closure(): TResult $save @return TResult */
    public function guardVariantSkuSave(Closure $save): mixed
    {
        return $this->uniqueConstraints->run(
            $save,
            'sku',
            'Такой SKU уже используется другим вариантом.',
            ['product_variants.sku', 'product_variants_sku_unique'],
        );
    }

    public function prepareForSave(ProductVariant $variant): void
    {
        $variant->product_id = $this->relationIds->positive($variant->product_id, 'product_id');

        if ($variant->exists && $variant->isDirty('product_id')) {
            $this->fail('product_id', 'Нельзя переносить существующий вариант между товарами.');
        }

        $variant->sku = filled($variant->sku) ? trim((string) $variant->sku) : null;

        Validator::make($variant->getAttributes(), [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'old_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
        ], $this->messages())->validate();

        Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
        $siblings = ProductVariant::query()
            ->where('product_id', $variant->product_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $otherDefaults = $siblings
            ->when($variant->exists, fn ($variants) => $variants->where('id', '!=', $variant->getKey()))
            ->where('is_default', true);

        if ($siblings->isEmpty() || (! $variant->is_default && $otherDefaults->isEmpty())) {
            $variant->is_default = true;
        }

        if ($variant->is_default) {
            ProductVariant::query()
                ->where('product_id', $variant->product_id)
                ->when($variant->exists, fn ($query) => $query->whereKeyNot($variant->getKey()))
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    public function setDefault(Product $product, ProductVariant $variant): ProductVariant
    {
        return DB::transaction(function () use ($product, $variant): ProductVariant {
            $lockedProduct = Product::query()->whereKey($product)->lockForUpdate()->firstOrFail();
            $variants = $lockedProduct->variants()->orderBy('id')->lockForUpdate()->get();
            $target = $variants->firstWhere('id', $variant->getKey());

            if (! $target instanceof ProductVariant) {
                $this->fail('variant', 'Нельзя назначить вариантом по умолчанию вариант другого товара.');
            }

            $lockedProduct->variants()->where('is_default', true)->whereKeyNot($target->getKey())->update(['is_default' => false]);
            $target->forceFill(['is_default' => true])->save();

            return $target->refresh();
        });
    }

    public function delete(ProductVariant $variant, ?ProductVariant $replacement = null): void
    {
        DB::transaction(function () use ($variant, $replacement): void {
            $productId = $this->relationIds->positive(
                $variant->getRawOriginal('product_id') ?? $variant->product_id,
                'product_id',
            );
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $variants = $product->variants()->orderBy('id')->lockForUpdate()->get();
            $target = $variants->firstWhere('id', $variant->getKey());

            if (! $target instanceof ProductVariant) {
                $this->fail('variant', 'Вариант товара был изменён или удалён. Обновите страницу.');
            }

            if ($variants->count() <= 1) {
                $this->fail('variant', 'Нельзя удалить последний вариант товара.');
            }

            if ($target->is_default) {
                $replacementTarget = $replacement instanceof ProductVariant
                    ? $variants->firstWhere('id', $replacement->getKey())
                    : null;

                if (! $replacementTarget instanceof ProductVariant || $replacementTarget->is($target)) {
                    $this->fail('variant', 'Перед удалением варианта по умолчанию выберите замену.');
                }

                $product->variants()
                    ->where('is_default', true)
                    ->whereKeyNot($replacementTarget->getKey())
                    ->update(['is_default' => false]);
                $replacementTarget->forceFill(['is_default' => true])->saveQuietly();
            }

            $target->deleteQuietly();
        });
    }

    public function assertCanDelete(ProductVariant $variant): void
    {
        if ($variant->is_default) {
            $this->fail('variant', 'Нельзя удалить вариант по умолчанию без выбора замены.');
        }

        if ($variant->product()->first()?->variants()->count() <= 1) {
            $this->fail('variant', 'Нельзя удалить последний вариант товара.');
        }
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'Поле обязательно.',
            'exists' => 'Товар варианта не существует.',
            'unique' => 'Такой SKU уже используется другим вариантом.',
            'numeric' => 'Цена должна быть числом.',
            'integer' => 'Остаток должен быть целым числом.',
            'min' => 'Цена и остаток не могут быть отрицательными.',
            'decimal' => 'Цена должна содержать не более двух знаков после запятой.',
            'max' => 'Значение превышает допустимую точность базы данных.',
        ];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
