<?php

namespace Database\Factories;

use App\Enums\HomepageMetricCode;
use App\Models\HomepageMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomepageMetric> */
class HomepageMetricFactory extends Factory
{
    protected $model = HomepageMetric::class;

    public function definition(): array
    {
        return [
            'code' => fake()->randomElement(HomepageMetricCode::cases()),
            'prefix' => null,
            'value' => (string) fake()->numberBetween(1, 3000),
            'suffix' => null,
            'text' => fake()->sentence(),
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
