<?php

namespace App\Services\Promotions;

final readonly class PromoCodePricingResult
{
    /**
     * @param  array<int, int>  $lineDiscountsCents
     * @param  array<int, int>  $eligibleLineTotalsCents
     */
    public function __construct(
        public bool $valid,
        public ?string $message,
        public int $eligibleSubtotalCents,
        public int $discountCents,
        public array $lineDiscountsCents = [],
        public array $eligibleLineTotalsCents = [],
    ) {}

    public static function invalid(string $message): self
    {
        return new self(false, $message, 0, 0);
    }

    public function discountAmount(): float
    {
        return $this->discountCents / 100;
    }

    public function lineDiscountAmount(int $cartItemId): float
    {
        return ($this->lineDiscountsCents[$cartItemId] ?? 0) / 100;
    }
}
