<?php

namespace App\Services\Catalog;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use Illuminate\Support\Collection;

final class ProductOptionCombinationCalculator
{
    public const MAX_COMBINATIONS = 100;

    public function countForTemplate(ProductOptionTemplate $template): int
    {
        return $this->countForValueGroups($this->activeLoadedValuesByGroup(
            $this->templateItems($template),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $items
     */
    public function countForItems(array $items): int
    {
        return $this->countForValueGroups($this->activeValuesByGroup($items));
    }

    public function exceedsLimitForTemplate(ProductOptionTemplate $template): bool
    {
        return $this->countForTemplate($template) > self::MAX_COMBINATIONS;
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $items
     */
    public function exceedsLimitForItems(array $items): bool
    {
        return $this->countForItems($items) > self::MAX_COMBINATIONS;
    }

    /** @return array<int, array<int, ProductOptionValue>> */
    public function combinationsForTemplate(ProductOptionTemplate $template): array
    {
        $valuesByGroup = $this->activeLoadedValuesByGroup($this->templateItems($template));

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

    /** @param Collection<int, Collection<int, ProductOptionValue>> $valuesByGroup */
    private function countForValueGroups(Collection $valuesByGroup): int
    {
        if ($valuesByGroup->isEmpty()) {
            return 0;
        }

        return $valuesByGroup->reduce(
            fn (int $count, Collection $values): int => $count > intdiv(PHP_INT_MAX, max(1, $values->count()))
                ? PHP_INT_MAX
                : $count * $values->count(),
            1,
        );
    }

    /** @return Collection<int, ProductOptionTemplateItem> */
    private function templateItems(ProductOptionTemplate $template): Collection
    {
        if (! $template->relationLoaded('items')) {
            $template->load(['items.group', 'items.value']);
        } elseif ($template->items->contains(fn (ProductOptionTemplateItem $item): bool => ! $item->relationLoaded('group') || ! $item->relationLoaded('value'))) {
            $template->loadMissing(['items.group', 'items.value']);
        }

        return $template->items;
    }

    /**
     * @param  Collection<int, ProductOptionTemplateItem>  $items
     * @return Collection<int, Collection<int, ProductOptionValue>>
     */
    private function activeLoadedValuesByGroup(Collection $items): Collection
    {
        return $items
            ->filter(function (ProductOptionTemplateItem $item): bool {
                $group = $item->group;
                $value = $item->value;

                return $group instanceof ProductOptionGroup
                    && $value instanceof ProductOptionValue
                    && $group->is_active
                    && $value->is_active
                    && (int) $value->product_option_group_id === (int) $group->getKey();
            })
            ->unique(fn (ProductOptionTemplateItem $item): string => $item->product_option_group_id.':'.$item->product_option_value_id)
            ->sortBy(fn (ProductOptionTemplateItem $item): string => sprintf(
                '%010d:%010d:%010d:%010d',
                (int) $item->group?->position,
                (int) $item->product_option_group_id,
                (int) $item->value?->position,
                (int) $item->product_option_value_id,
            ))
            ->groupBy(fn (ProductOptionTemplateItem $item): int => (int) $item->product_option_group_id)
            ->map(fn (Collection $groupItems): Collection => $groupItems
                ->map(fn (ProductOptionTemplateItem $item): ProductOptionValue => $item->value)
                ->values())
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $items
     * @return Collection<int, Collection<int, ProductOptionValue>>
     */
    private function activeValuesByGroup(array $items): Collection
    {
        $pairs = collect($items)
            ->map(function (array|object $item): array {
                return [
                    'group_id' => (int) data_get($item, 'product_option_group_id', 0),
                    'value_id' => (int) data_get($item, 'product_option_value_id', 0),
                ];
            })
            ->filter(fn (array $pair): bool => $pair['group_id'] > 0 && $pair['value_id'] > 0)
            ->unique(fn (array $pair): string => $pair['group_id'].':'.$pair['value_id'])
            ->values();

        if ($pairs->isEmpty()) {
            return collect();
        }

        $groupIds = $pairs->pluck('group_id')->unique();
        $valueIds = $pairs->pluck('value_id')->unique();

        $groups = ProductOptionGroup::query()
            ->whereKey($groupIds)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $values = ProductOptionValue::query()
            ->with('group')
            ->whereKey($valueIds)
            ->whereIn('product_option_group_id', $groupIds)
            ->where('is_active', true)
            ->get()
            ->filter(fn (ProductOptionValue $value): bool => $pairs->contains(
                fn (array $pair): bool => $pair['group_id'] === (int) $value->product_option_group_id
                    && $pair['value_id'] === (int) $value->getKey(),
            ))
            ->sortBy(fn (ProductOptionValue $value): string => sprintf(
                '%010d:%010d:%010d',
                (int) $value->group?->position,
                (int) $value->position,
                (int) $value->getKey(),
            ));

        return $groups->map(fn (ProductOptionGroup $group): Collection => $values
            ->where('product_option_group_id', $group->getKey())
            ->values());
    }
}
