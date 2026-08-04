<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use Illuminate\Validation\ValidationException;

trait HandlesProductOptionValues
{
    /** @var array<int, array<string, bool>> */
    protected array $persistedVariantOptionPairs = [];

    protected function capturePersistedProductOptionSelections(): void
    {
        $this->persistedVariantOptionPairs = $this->record->variants()
            ->with('variantOptionValues')
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                (int) $variant->getKey() => $variant->variantOptionValues
                    ->mapWithKeys(fn (ProductVariantOptionValue $selection): array => [
                        ((int) $selection->product_option_group_id).':'.((int) $selection->product_option_value_id) => true,
                    ])
                    ->all(),
            ])
            ->all();
    }

    protected function finishProductOptionSave(): void
    {
        /** @var Product $product */
        $product = $this->record->refresh()->load('optionTemplate.items');
        $template = $product->optionTemplate;
        $templateItems = $template instanceof ProductOptionTemplate
            ? $template->items
            : collect();
        $allowedGroupIds = $templateItems->pluck('product_option_group_id')->map(fn (mixed $id): int => (int) $id)->unique();
        $allowedPairs = $templateItems
            ->mapWithKeys(fn ($item): array => [
                ((int) $item->product_option_group_id).':'.((int) $item->product_option_value_id) => true,
            ]);

        $signatures = [];

        $product->variants()
            ->with(['optionValues.group', 'variantOptionValues.group', 'variantOptionValues.value'])
            ->each(function (ProductVariant $variant) use (&$signatures, $template, $allowedGroupIds, $allowedPairs): void {
                $this->validateVariantOptionSelections(
                    $variant,
                    $template,
                    $allowedGroupIds->all(),
                    $allowedPairs->all(),
                );

                if ($variant->optionValues->isNotEmpty()) {
                    $variant->syncOptionsSnapshotFromValues();

                    $signature = $variant->optionValues
                        ->map(fn ($value): string => $value->product_option_group_id.':'.$value->getKey())
                        ->sort()
                        ->implode('|');

                    if (isset($signatures[$signature])) {
                        throw ValidationException::withMessages([
                            'data.variants' => 'У двух вариантов выбрана одинаковая комбинация опций.',
                        ]);
                    }

                    $signatures[$signature] = true;
                }
            });

        $product->unsetRelation('variants');
    }

    /**
     * @param  array<int, int>  $allowedGroupIds
     * @param  array<string, bool>  $allowedPairs
     */
    private function validateVariantOptionSelections(
        ProductVariant $variant,
        ?ProductOptionTemplate $template,
        array $allowedGroupIds,
        array $allowedPairs,
    ): void {
        $seenGroupIds = [];

        foreach ($variant->variantOptionValues as $selection) {
            if (! $selection instanceof ProductVariantOptionValue) {
                continue;
            }

            $groupId = (int) $selection->product_option_group_id;
            $valueId = (int) $selection->product_option_value_id;
            $group = $selection->group;
            $value = $selection->value;

            if (isset($seenGroupIds[$groupId])) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Для варианта можно выбрать только одно значение из каждой группы опций.',
                ]);
            }

            $seenGroupIds[$groupId] = true;

            if (! $value instanceof ProductOptionValue || (int) $value->product_option_group_id !== $groupId) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Значение не принадлежит выбранной группе опций.',
                ]);
            }

            if (! $template instanceof ProductOptionTemplate) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Для выбранных опций необходимо указать шаблон товара.',
                ]);
            }

            $pair = $groupId.':'.$valueId;
            $wasPersisted = isset($this->persistedVariantOptionPairs[(int) $variant->getKey()][$pair]);

            if (! $wasPersisted && ! $template->is_active) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Новые значения опций можно добавлять только из активного шаблона товара.',
                ]);
            }

            if (! $wasPersisted && (! $group instanceof ProductOptionGroup || ! $group->is_active)) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Нельзя добавить значение из неактивной группы опций.',
                ]);
            }

            if (! $wasPersisted && ! $value->is_active) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Нельзя добавить неактивное значение опции.',
                ]);
            }

            if (! $wasPersisted && ! in_array($groupId, $allowedGroupIds, true)) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Выбранная группа опций не разрешена шаблоном товара.',
                ]);
            }

            if (! $wasPersisted && ! isset($allowedPairs[$pair])) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Выбранное значение опции не разрешено шаблоном товара.',
                ]);
            }
        }
    }
}
