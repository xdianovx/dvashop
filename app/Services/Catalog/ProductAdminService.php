<?php

namespace App\Services\Catalog;

use App\Enums\ProductType;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductOptionTemplate;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductAdminService
{
    public function __construct(
        private readonly ProductOptionTemplateResolver $templateResolver,
        private readonly KnownUniqueConstraintGuard $uniqueConstraints,
        private readonly CatalogRelationIdNormalizer $relationIds,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(Product $product, array $data): Product
    {
        if (array_key_exists('product_type', $data)) {
            $data['product_type'] = $this->normalizeProductType($data['product_type']);
        }

        return DB::transaction(fn (): Product => $this->guardProductSkuSave(
            function () use ($product, $data): Product {
                if ($product->exists) {
                    Product::withTrashed()->whereKey($product)->lockForUpdate()->firstOrFail();
                }

                $product->fill($data)->save();

                return $product->refresh();
            },
        ));
    }

    /** @template TResult @param Closure(): TResult $save @return TResult */
    public function guardProductSkuSave(Closure $save): mixed
    {
        return $this->uniqueConstraints->run(
            $save,
            'sku',
            'Такой SKU уже используется другим товаром.',
            ['products.sku', 'products_sku_unique'],
        );
    }

    public function prepareForSave(Product $product): void
    {
        $product->sku = filled($product->sku) ? trim((string) $product->sku) : null;
        $categoryId = $this->relationIds->nullablePositive($product->product_category_id, 'product_category_id');
        $partTypeId = $this->relationIds->nullablePositive($product->part_type_id, 'part_type_id');
        $templateId = $this->relationIds->nullablePositive(
            $product->product_option_template_id,
            'product_option_template_id',
        );
        $product->product_category_id = $categoryId;
        $product->part_type_id = $partTypeId;
        $product->product_option_template_id = $templateId;

        Validator::make($product->getAttributes(), [
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product)],
            'price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'old_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
        ], $this->messages())->validate();

        $category = $categoryId === null
            ? null
            : ProductCategory::withTrashed()->whereKey($categoryId)->lockForUpdate()->first();

        $historicalCategory = $this->isHistoricalRelation($product, 'product_category_id', $categoryId);

        if ($categoryId !== null
            && (! $category instanceof ProductCategory || $category->trashed())
            && ! $historicalCategory) {
            $this->fail('product_category_id', 'Выбранная категория не существует или удалена.');
        }

        if ($category instanceof ProductCategory
            && (! $product->exists || $product->isDirty('product_category_id'))
            && ! $category->is_active) {
            $this->fail('product_category_id', 'Нельзя назначить товару неактивную категорию.');
        }

        $attributes = $product->getAttributes();
        $type = array_key_exists('product_type', $attributes)
            ? $this->normalizeProductType($attributes['product_type'])
            : ProductType::AutoPart;

        $product->product_type = $type;

        if ($type === ProductType::Generic) {
            if ($product->part_type_id !== null || $product->product_option_template_id !== null) {
                $this->fail('product_type', 'У обычного товара не должно быть типа детали или шаблона автодетали.');
            }

            if ($product->exists && $product->fitments()->exists()) {
                $this->fail('product_type', 'Перед переводом в обычный товар удалите применяемость к автомобилям.');
            }

            return;
        }

        $partType = $partTypeId === null
            ? null
            : PartType::withTrashed()->whereKey($partTypeId)->lockForUpdate()->first();

        $historicalPartType = $this->isHistoricalRelation($product, 'part_type_id', $partTypeId);

        if ($partTypeId !== null
            && (! $partType instanceof PartType || $partType->trashed())
            && ! $historicalPartType) {
            $this->fail('part_type_id', 'Выбранный тип детали не существует или удалён.');
        }

        if ($partType instanceof PartType
            && (! $product->exists || $product->isDirty('part_type_id'))
            && ! $partType->is_active) {
            $this->fail('part_type_id', 'Нельзя назначить товару неактивный тип детали.');
        }

        $template = $templateId === null
            ? null
            : ProductOptionTemplate::query()->whereKey($templateId)->lockForUpdate()->first();

        $this->validateTemplate($product, $type, $template);
    }

    public function clearAutoPartRelationsForGenericTransition(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->whereKey($product)->lockForUpdate()->firstOrFail();
            $locked->fitments()->lockForUpdate()->get();
            $locked->fitments()->delete();
        });
    }

    /**
     * @param  array<int, array{vehicle_generation_id:mixed,note?:mixed,is_primary?:mixed}>  $fitments
     */
    public function syncFitments(Product $product, array $fitments): void
    {
        DB::transaction(function () use ($product, $fitments): void {
            $locked = Product::query()->whereKey($product)->lockForUpdate()->firstOrFail();

            if (! $locked->isAutoPart()) {
                $this->fail('fitments', 'Применяемость можно указать только для автодетали.');
            }

            $normalized = [];

            foreach (array_values($fitments) as $index => $fitment) {
                $generationId = $this->relationIds->positive(
                    is_array($fitment) ? ($fitment['vehicle_generation_id'] ?? null) : null,
                    "fitments.{$index}.vehicle_generation_id",
                );

                if (isset($normalized[$generationId])) {
                    $this->fail("fitments.{$index}.vehicle_generation_id", 'Такая применяемость уже добавлена товару.');
                }

                $generation = VehicleGeneration::withTrashed()
                    ->with(['model' => fn ($query) => $query->withTrashed()->with(['make' => fn ($make) => $make->withTrashed()])])
                    ->find($generationId);
                $historical = $locked->fitments()->where('vehicle_generation_id', $generationId)->exists();

                if (! $generation instanceof VehicleGeneration || ! $generation->model || ! $generation->model->make
                    || (! $historical && ($generation->trashed() || $generation->model->trashed() || $generation->model->make->trashed()))) {
                    $this->fail("fitments.{$index}.vehicle_generation_id", 'Выбранная автомобильная иерархия не существует или удалена.');
                }

                if (! $historical && (! $generation->is_active || ! $generation->model->is_active || ! $generation->model->make->is_active)) {
                    $this->fail("fitments.{$index}.vehicle_generation_id", 'Нельзя добавить неактивное поколение, модель или марку.');
                }

                $normalized[$generationId] = [
                    'note' => filled($fitment['note'] ?? null) ? trim((string) $fitment['note']) : null,
                    'is_primary' => (bool) ($fitment['is_primary'] ?? false),
                ];
            }

            $existing = $locked->fitments()->lockForUpdate()->get()->keyBy('vehicle_generation_id');

            foreach ($normalized as $generationId => $attributes) {
                $current = $existing->get($generationId);

                if ($current instanceof ProductFitment) {
                    if ($current->note !== $attributes['note'] || (bool) $current->is_primary !== $attributes['is_primary']) {
                        $current->fill($attributes)->save();
                    }
                } else {
                    $locked->fitments()->create(['vehicle_generation_id' => $generationId, ...$attributes]);
                }
            }

            $locked->fitments()->whereNotIn('vehicle_generation_id', array_keys($normalized))->delete();
        });
    }

    public function validateFitment(ProductFitment $fitment): void
    {
        $fitment->product_id = $this->relationIds->positive($fitment->product_id, 'product_id');
        $fitment->vehicle_generation_id = $this->relationIds->positive(
            $fitment->vehicle_generation_id,
            'vehicle_generation_id',
        );

        $product = Product::query()->find($fitment->product_id);

        if (! $product instanceof Product || ! $product->isAutoPart()) {
            $this->fail('product_id', 'Применяемость можно сохранить только для существующей автодетали.');
        }

        if ($fitment->exists && ! $fitment->isDirty('vehicle_generation_id') && ! $fitment->isDirty('product_id')) {
            return;
        }

        $generation = VehicleGeneration::withTrashed()
            ->with(['model' => fn ($query) => $query->withTrashed()->with(['make' => fn ($make) => $make->withTrashed()])])
            ->find($fitment->vehicle_generation_id);

        if (! $generation instanceof VehicleGeneration || $generation->trashed()
            || ! $generation->is_active || ! $generation->model || $generation->model->trashed()
            || ! $generation->model->is_active || ! $generation->model->make
            || $generation->model->make->trashed() || ! $generation->model->make->is_active) {
            $this->fail('vehicle_generation_id', 'Нельзя добавить неактивную или удалённую автомобильную иерархию.');
        }

        if (ProductFitment::query()
            ->where('product_id', $fitment->product_id)
            ->where('vehicle_generation_id', $fitment->vehicle_generation_id)
            ->when($fitment->exists, fn ($query) => $query->whereKeyNot($fitment->getKey()))
            ->exists()) {
            $this->fail('vehicle_generation_id', 'Такая применяемость уже добавлена товару.');
        }
    }

    /** @param Closure(): bool $save */
    public function saveFitment(ProductFitment $fitment, Closure $save): bool
    {
        return DB::transaction(function () use ($fitment, $save): bool {
            $fitment->product_id = $this->relationIds->positive($fitment->product_id, 'product_id');
            $fitment->vehicle_generation_id = $this->relationIds->positive(
                $fitment->vehicle_generation_id,
                'vehicle_generation_id',
            );

            $product = Product::query()->whereKey($fitment->product_id)->lockForUpdate()->first();
            $this->lockVehicleHierarchyForFitment($fitment);
            $product?->fitments()->lockForUpdate()->get();

            return (bool) $this->uniqueConstraints->run(
                $save,
                'vehicle_generation_id',
                'Такая применяемость уже добавлена товару.',
                [
                    'product_fitments.product_id, product_fitments.vehicle_generation_id',
                    'product_fitments_product_id_vehicle_generation_id_unique',
                ],
            );
        });
    }

    private function validateTemplate(
        Product $product,
        ProductType $type,
        ?ProductOptionTemplate $template,
    ): void {
        if ($product->product_option_template_id === null) {
            return;
        }

        if (! $template instanceof ProductOptionTemplate) {
            $this->fail('product_option_template_id', 'Выбранный шаблон опций не существует.');
        }

        if (! $this->templateResolver->isCompatible($template, $type, $product->part_type_id)) {
            $this->fail('product_option_template_id', 'Шаблон опций несовместим с типом товара или типом детали.');
        }

        if ((! $product->exists || $product->isDirty('product_option_template_id')) && ! $template->is_active) {
            $this->fail('product_option_template_id', 'Нельзя назначить неактивный шаблон опций.');
        }
    }

    private function isHistoricalRelation(Product $product, string $field, ?int $currentId): bool
    {
        if (! $product->exists) {
            return false;
        }

        $originalId = $this->relationIds->nullablePositive($product->getRawOriginal($field), $field);

        return $originalId === $currentId;
    }

    private function lockVehicleHierarchyForFitment(ProductFitment $fitment): void
    {
        $requiresActiveHierarchy = ! $fitment->exists || $fitment->isDirty('vehicle_generation_id');
        $generation = VehicleGeneration::withTrashed()
            ->whereKey($fitment->vehicle_generation_id)
            ->lockForUpdate()
            ->first();

        if (! $generation instanceof VehicleGeneration) {
            if ($requiresActiveHierarchy) {
                $this->fail('vehicle_generation_id', 'Нельзя добавить неактивную или удалённую автомобильную иерархию.');
            }

            return;
        }

        $modelId = $this->relationIds->positive($generation->vehicle_model_id, 'vehicle_model_id');
        $model = $generation->model()->withTrashed()->whereKey($modelId)->lockForUpdate()->first();

        if (! $model instanceof VehicleModel) {
            if ($requiresActiveHierarchy) {
                $this->fail('vehicle_generation_id', 'Нельзя добавить неактивную или удалённую автомобильную иерархию.');
            }

            return;
        }

        $makeId = $this->relationIds->positive($model->vehicle_make_id, 'vehicle_make_id');
        $make = $model->make()->withTrashed()->whereKey($makeId)->lockForUpdate()->first();

        if ($requiresActiveHierarchy
            && ($generation->trashed() || ! $generation->is_active
                || $model->trashed() || ! $model->is_active
                || ! $make instanceof VehicleMake || $make->trashed() || ! $make->is_active)) {
            $this->fail('vehicle_generation_id', 'Нельзя добавить неактивную или удалённую автомобильную иерархию.');
        }
    }

    private function normalizeProductType(mixed $value): ProductType
    {
        $type = $value instanceof ProductType
            ? $value
            : (is_string($value) ? ProductType::tryFrom($value) : null);

        if (! $type instanceof ProductType) {
            $this->fail('product_type', 'Выбран некорректный тип товара.');
        }

        return $type;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'unique' => 'Такой SKU уже используется другим товаром.',
            'numeric' => 'Цена должна быть числом.',
            'min' => 'Цена не может быть отрицательной.',
            'decimal' => 'Цена должна содержать не более двух знаков после запятой.',
            'max' => 'Значение превышает допустимую точность базы данных.',
        ];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
