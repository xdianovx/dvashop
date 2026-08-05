<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\PaymentMethodSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethodSetting> */
class PaymentMethodSettingFactory extends Factory
{
    protected $model = PaymentMethodSetting::class;

    public function definition(): array
    {
        $method = fake()->randomElement(PaymentMethod::cases());

        return [
            'code' => $method,
            'title' => $method->label(),
            'description' => null,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
