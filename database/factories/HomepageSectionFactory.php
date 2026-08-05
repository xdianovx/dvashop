<?php

namespace Database\Factories;

use App\Enums\HomepageSectionCode;
use App\Models\HomepageSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomepageSection> */
class HomepageSectionFactory extends Factory
{
    protected $model = HomepageSection::class;

    public function definition(): array
    {
        return [
            'code' => fake()->randomElement(HomepageSectionCode::cases()),
            'title' => fake()->sentence(3),
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
