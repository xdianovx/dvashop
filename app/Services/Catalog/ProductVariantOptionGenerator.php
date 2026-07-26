<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class ProductVariantOptionGenerator
{
    public const MAX_COMBINATIONS = 100;

    /** @return array<int, int> group id => default value id */
    public function defaultOptionValues(Product $product): array
    {
        $template = $product->optionTemplate;

        if (! $template instanceof ProductOptionTemplate) {
            return [];
        }

        return $template->items()
            ->with('value')
            ->get()
            ->filter(fn ($item): bool => (bool) $item->value?->is_active && (bool) $item->value?->is_default)
            ->sortBy('position')
            ->mapWithKeys(fn ($item): array => [
                (int) $item->product_option_group_id => (int) $item->product_option_value_id,
            ])
            ->all();
    }

    /** @return array<int, array<int, ProductOptionValue>> */
    public function combinationsForTemplate(ProductOptionTemplate $template): array
    {
        $valuesByGroup = $template->items()
            ->with(['group', 'value.group'])
            ->get()
            ->filter(fn ($item): bool => (bool) $item->group?->is_active && (bool) $item->value?->is_active)
            ->sortBy(fn ($item): string => sprintf(
                '%010d:%010d:%010d',
                (int) $item->group?->position,
                (int) $item->value?->position,
                (int) $item->getKey(),
            ))
            ->groupBy('product_option_group_id')
            ->map(fn ($items): array => $items->pluck('value')->filter()->values()->all())
            ->filter(fn (array $values): bool => $values !== [])
            ->values();

        if ($valuesByGroup->isEmpty()) {
            return [];
        }

        $combinations = [[]];

        foreach ($valuesByGroup as $values) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = [...$combination, $value];

                    if (count($next) >= self::MAX_COMBINATIONS) {
                        break 2;
                    }
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    public function createMissingVariants(Product $product, int $limit = self::MAX_COMBINATIONS): int
    {
        $limit = min(self::MAX_COMBINATIONS, max(1, $limit));

        return DB::transaction(function () use ($product, $limit): int {
            $lockedProduct = Product::query()
                ->with('optionTemplate')
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $template = $lockedProduct->optionTemplate;

            if (! $template instanceof ProductOptionTemplate || ! $template->is_active) {
                return 0;
            }

            $combinations = array_slice($this->combinationsForTemplate($template), 0, $limit);

            if ($combinations === []) {
                return 0;
            }

            $baseVariant = $lockedProduct->defaultVariant()->first()
                ?? $lockedProduct->variants()->orderBy('id')->first();

            if (! $baseVariant instanceof ProductVariant) {
                $baseVariant = $lockedProduct->variants()->create([
                    'sku' => $lockedProduct->sku,
                    'title' => 'Основной',
                    'price' => $lockedProduct->price ?? 0,
                    'old_price' => $lockedProduct->old_price,
                    'stock_quantity' => null,
                    'stock_status' => $lockedProduct->stock_status,
                    'is_default' => true,
                    'is_active' => true,
                ]);
            } elseif (! $baseVariant->is_default) {
                $baseVariant->forceFill(['is_default' => true])->save();
            }

            $variants = $lockedProduct->variants()->with('optionValues.group')->orderBy('id')->get();
            $templateValues = collect($combinations)
                ->flatten(1)
                ->unique(fn (ProductOptionValue $value): int => (int) $value->getKey())
                ->values();
            $existingSignatures = $variants
                ->mapWithKeys(function (ProductVariant $variant) use ($templateValues): array {
                    $signature = $variant->optionValues->isNotEmpty()
                        ? $this->signature($variant->optionValues->all())
                        : $this->signatureFromSnapshot($variant, $templateValues->all());

                    return $signature === '' ? [] : [$signature => true];
                })
                ->all();
            $defaultSignature = $this->signatureFromMap($this->defaultOptionValues($lockedProduct));
            $created = 0;

            foreach ($combinations as $combination) {
                $signature = $this->signature($combination);

                if (isset($existingSignatures[$signature])) {
                    continue;
                }

                if ($baseVariant->optionValues()->doesntExist() && $signature === $defaultSignature) {
                    $this->storeValues($baseVariant, $combination);
                    $existingSignatures[$signature] = true;

                    continue;
                }

                $variant = $lockedProduct->variants()->create([
                    'sku' => null,
                    'title' => null,
                    'price' => $baseVariant->price,
                    'old_price' => $baseVariant->old_price,
                    'stock_quantity' => $baseVariant->stock_quantity,
                    'stock_status' => $baseVariant->stock_status,
                    'is_default' => false,
                    'is_active' => $baseVariant->is_active,
                ]);

                $this->storeValues($variant, $combination);
                $existingSignatures[$signature] = true;
                $created++;
            }

            return $created;
        });
    }

    /** @param array<int, ProductOptionValue> $values */
    private function storeValues(ProductVariant $variant, array $values): void
    {
        foreach ($values as $value) {
            $variant->variantOptionValues()->create([
                'product_option_group_id' => $value->product_option_group_id,
                'product_option_value_id' => $value->getKey(),
            ]);
        }

        $variant->syncOptionsSnapshotFromValues();
        $variant->forceFill(['title' => $variant->optionSummary()])->saveQuietly();
    }

    /** @param array<int, ProductOptionValue> $values */
    private function signature(array $values): string
    {
        return collect($values)
            ->mapWithKeys(fn (ProductOptionValue $value): array => [
                (int) $value->product_option_group_id => (int) $value->getKey(),
            ])
            ->sortKeys()
            ->map(fn (int $valueId, int $groupId): string => $groupId.':'.$valueId)
            ->implode('|');
    }

    /** @param array<int, int> $valuesByGroup */
    private function signatureFromMap(array $valuesByGroup): string
    {
        return collect($valuesByGroup)
            ->sortKeys()
            ->map(fn (int $valueId, int $groupId): string => $groupId.':'.$valueId)
            ->implode('|');
    }

    /** @param array<int, ProductOptionValue> $templateValues */
    private function signatureFromSnapshot(ProductVariant $variant, array $templateValues): string
    {
        if (! is_array($variant->options) || $variant->options === []) {
            return '';
        }

        $matched = [];

        foreach ($variant->options as $groupKey => $option) {
            $groupLabel = is_array($option) ? ($option['group'] ?? $groupKey) : $groupKey;
            $valueLabel = is_array($option) ? ($option['value'] ?? null) : $option;

            if (! is_scalar($valueLabel)) {
                continue;
            }

            $value = collect($templateValues)->first(function (ProductOptionValue $candidate) use ($groupKey, $groupLabel, $valueLabel): bool {
                $group = $candidate->group;

                if ($group === null) {
                    return false;
                }

                $groupMatches = in_array((string) $groupKey, [$group->code, $group->slug], true)
                    || (string) $groupLabel === $group->title;
                $valueMatches = in_array((string) $valueLabel, [$candidate->title, $candidate->code, $candidate->slug], true);

                return $groupMatches && $valueMatches;
            });

            if ($value instanceof ProductOptionValue) {
                $matched[(int) $value->product_option_group_id] = (int) $value->getKey();
            }
        }

        return $this->signatureFromMap($matched);
    }
}
