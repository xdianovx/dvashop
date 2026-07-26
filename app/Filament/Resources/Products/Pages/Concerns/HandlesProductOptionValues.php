<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Models\Product;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use Illuminate\Validation\ValidationException;

trait HandlesProductOptionValues
{
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
            ->with(['optionValues.group', 'variantOptionValues.value'])
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

            if (! in_array($groupId, $allowedGroupIds, true)) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Выбранная группа опций не разрешена шаблоном товара.',
                ]);
            }

            if (! isset($allowedPairs[$groupId.':'.$valueId])) {
                throw ValidationException::withMessages([
                    'data.variants' => 'Выбранное значение опции не разрешено шаблоном товара.',
                ]);
            }
        }
    }
}
