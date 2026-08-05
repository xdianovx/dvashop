<?php

namespace Database\Factories;

use App\Enums\StaticPageCode;
use App\Enums\StaticPageSectionCode;
use App\Models\StaticPage;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaticPageSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'static_page_id' => StaticPage::factory()->state(['code' => StaticPageCode::About]),
            'code' => StaticPageSectionCode::AboutHero,
            'label' => fake()->optional()->words(2, true),
            'title' => fake()->optional()->sentence(3),
            'subtitle' => fake()->optional()->sentence(),
            'body' => fake()->optional()->paragraph(),
            'is_active' => true,
            'position' => 0,
        ];
    }
}
