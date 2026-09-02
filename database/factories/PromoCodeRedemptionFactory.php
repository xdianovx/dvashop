<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromoCodeRedemption> */
class PromoCodeRedemptionFactory extends Factory
{
    protected $model = PromoCodeRedemption::class;

    public function definition(): array
    {
        return [
            'promo_code_id' => PromoCode::factory(),
            'order_id' => Order::factory(),
            'discount_amount' => fake()->randomFloat(2, 100, 3000),
            'released_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => ['released_at' => now()]);
    }
}
