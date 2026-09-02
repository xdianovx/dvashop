<?php

namespace Database\Factories;

use App\Enums\PromoDiscountType;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PromoCode> */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    public function definition(): array
    {
        return [
            'code' => 'TEST-'.Str::upper(Str::random(8)),
            'name' => fake()->words(3, true),
            'description' => null,
            'discount_type' => PromoDiscountType::Percentage,
            'discount_value' => 10,
            'max_discount_amount' => null,
            'minimum_eligible_subtotal' => null,
            'applies_to_all' => true,
            'allow_sale_items' => false,
            'usage_limit' => null,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function fixed(float $amount = 1000): static
    {
        return $this->state(fn (): array => [
            'discount_type' => PromoDiscountType::Fixed,
            'discount_value' => $amount,
            'max_discount_amount' => null,
        ]);
    }

    public function targeted(): static
    {
        return $this->state(fn (): array => ['applies_to_all' => false]);
    }
}
