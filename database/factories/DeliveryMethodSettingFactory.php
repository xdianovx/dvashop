<?php

namespace Database\Factories;

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Models\DeliveryMethodSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryMethodSetting> */
class DeliveryMethodSettingFactory extends Factory
{
    protected $model = DeliveryMethodSetting::class;

    public function definition(): array
    {
        $method = fake()->randomElement(DeliveryMethod::cases());

        return [
            'code' => $method,
            'title' => $method->label(),
            'description' => null,
            'base_price' => 0,
            'price_mode' => DeliveryPriceMode::Free,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
