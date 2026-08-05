<?php

namespace Database\Factories;

use App\Enums\StaticPageCode;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaticPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => StaticPageCode::About,
            'title' => fake()->sentence(3),
            'subtitle' => fake()->optional()->sentence(),
            'primary_action_label' => fake()->optional()->words(2, true),
            'secondary_action_label' => fake()->optional()->words(2, true),
            'is_active' => true,
            'position' => 0,
        ];
    }
}
