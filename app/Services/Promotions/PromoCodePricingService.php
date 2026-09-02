<?php

namespace App\Services\Promotions;

use App\Enums\PromoDiscountType;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Collection;

final class PromoCodePricingService
{
    /**
     * @param  Collection<int, CartItem>  $items
     */
    public function calculate(
        PromoCode $promo,
        Collection $items,
        ?int $activeUsageCount = null,
    ): PromoCodePricingResult {
        $availabilityError = $this->availabilityError($promo, $activeUsageCount);

        if ($availabilityError !== null) {
            return PromoCodePricingResult::invalid($availabilityError);
        }

        if ($items->isEmpty()) {
            return PromoCodePricingResult::invalid('Добавьте товары в корзину перед применением промокода.');
        }

        if ($items->contains(fn (CartItem $item): bool => $this->moneyToCents($item->price_snapshot) <= 0)) {
            return PromoCodePricingResult::invalid('Промокод нельзя применить к корзине с ценой по запросу.');
        }

        if (! $promo->applies_to_all) {
            $items->loadMissing('product:id,product_category_id,part_type_id');
        }

        $productIds = $promo->applies_to_all ? [] : $this->relationIds($promo, 'products');
        $categoryIds = $promo->applies_to_all ? [] : $this->relationIds($promo, 'productCategories');
        $partTypeIds = $promo->applies_to_all ? [] : $this->relationIds($promo, 'partTypes');
        $eligibleLines = [];

        foreach ($items->sortBy('id') as $item) {
            if (! $promo->allow_sale_items && $this->isSaleItem($item)) {
                continue;
            }

            if (! $promo->applies_to_all
                && ! $this->matchesTargets($item->product, $productIds, $categoryIds, $partTypeIds)) {
                continue;
            }

            $eligibleLines[(int) $item->getKey()] = $this->moneyToCents($item->price_snapshot)
                * max(1, (int) $item->quantity);
        }

        $eligibleSubtotal = array_sum($eligibleLines);

        if ($eligibleSubtotal <= 0) {
            return PromoCodePricingResult::invalid('В корзине нет товаров, подходящих под этот промокод.');
        }

        $minimum = $this->moneyToCents($promo->minimum_eligible_subtotal);

        if ($minimum > 0 && $eligibleSubtotal < $minimum) {
            return PromoCodePricingResult::invalid('Не достигнута минимальная сумма подходящих товаров для промокода.');
        }

        $discount = match ($promo->discount_type) {
            PromoDiscountType::Percentage => $this->percentageDiscount($eligibleSubtotal, (string) $promo->discount_value),
            PromoDiscountType::Fixed => $this->moneyToCents($promo->discount_value),
        };

        if ($promo->discount_type === PromoDiscountType::Percentage
            && $promo->max_discount_amount !== null) {
            $discount = min($discount, $this->moneyToCents($promo->max_discount_amount));
        }

        $discount = max(0, min($discount, $eligibleSubtotal));

        return new PromoCodePricingResult(
            valid: true,
            message: null,
            eligibleSubtotalCents: $eligibleSubtotal,
            discountCents: $discount,
            lineDiscountsCents: $this->allocate($eligibleLines, $discount),
            eligibleLineTotalsCents: $eligibleLines,
        );
    }

    public function moneyToCents(float|int|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $normalized = trim(str_replace(',', '.', (string) $amount));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = substr((preg_replace('/\D/', '', $fraction) ?: '').'00', 0, 2);
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function availabilityError(PromoCode $promo, ?int $activeUsageCount): ?string
    {
        if ($promo->trashed() || ! $promo->is_active) {
            return 'Промокод не действует.';
        }

        if ($promo->starts_at !== null && $promo->starts_at->isFuture()) {
            return 'Промокод ещё не начал действовать.';
        }

        if ($promo->ends_at !== null && $promo->ends_at->isPast()) {
            return 'Срок действия промокода истёк.';
        }

        if ($promo->usage_limit !== null) {
            $activeUsageCount ??= isset($promo->active_redemptions_count)
                ? (int) $promo->active_redemptions_count
                : $promo->activeRedemptions()->count();

            if ($activeUsageCount >= $promo->usage_limit) {
                return 'Лимит использований промокода исчерпан.';
            }
        }

        return null;
    }

    /** @return array<int, int> */
    private function relationIds(PromoCode $promo, string $relation): array
    {
        if (! $promo->relationLoaded($relation)) {
            $promo->load($relation.':id');
        }

        return $promo->{$relation}->modelKeys();
    }

    private function isSaleItem(CartItem $item): bool
    {
        return $item->old_price_snapshot !== null
            && $this->moneyToCents($item->old_price_snapshot) > $this->moneyToCents($item->price_snapshot);
    }

    /** @param array<int, int> $productIds @param array<int, int> $categoryIds @param array<int, int> $partTypeIds */
    private function matchesTargets(
        ?Product $product,
        array $productIds,
        array $categoryIds,
        array $partTypeIds,
    ): bool {
        if (! $product instanceof Product) {
            return false;
        }

        return in_array((int) $product->getKey(), $productIds, true)
            || ($product->product_category_id !== null
                && in_array((int) $product->product_category_id, $categoryIds, true))
            || ($product->part_type_id !== null
                && in_array((int) $product->part_type_id, $partTypeIds, true));
    }

    private function percentageDiscount(int $eligibleSubtotal, string $percentage): int
    {
        $scaledPercentage = $this->decimalToScaledInteger($percentage, 4);

        return intdiv(($eligibleSubtotal * $scaledPercentage) + 500_000, 1_000_000);
    }

    private function decimalToScaledInteger(string $value, int $scale): int
    {
        $normalized = trim(str_replace(',', '.', $value));
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = substr((preg_replace('/\D/', '', $fraction) ?: '').str_repeat('0', $scale), 0, $scale);

        return ((int) $whole * (10 ** $scale)) + (int) $fraction;
    }

    /**
     * @param  array<int, int>  $eligibleLines
     * @return array<int, int>
     */
    private function allocate(array $eligibleLines, int $discount): array
    {
        if ($discount <= 0 || $eligibleLines === []) {
            return array_fill_keys(array_keys($eligibleLines), 0);
        }

        ksort($eligibleLines);
        $eligibleSubtotal = array_sum($eligibleLines);
        $allocated = [];

        foreach ($eligibleLines as $itemId => $lineTotal) {
            $allocated[$itemId] = min($lineTotal, intdiv($discount * $lineTotal, $eligibleSubtotal));
        }

        $remainder = $discount - array_sum($allocated);

        while ($remainder > 0) {
            $distributed = false;

            foreach ($eligibleLines as $itemId => $lineTotal) {
                if ($remainder === 0) {
                    break;
                }

                if ($allocated[$itemId] >= $lineTotal) {
                    continue;
                }

                $allocated[$itemId]++;
                $remainder--;
                $distributed = true;
            }

            if (! $distributed) {
                break;
            }
        }

        return $allocated;
    }
}
