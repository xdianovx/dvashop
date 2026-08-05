<?php

namespace Database\Factories;

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\StaticPage;
use App\Models\StaticPageSection;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaticPageItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'static_page_section_id' => StaticPageSection::factory()->for(
                StaticPage::factory()->state(['code' => StaticPageCode::About]),
                'page',
            )->state(['code' => StaticPageSectionCode::AboutMetrics]),
            'code' => StaticPageItemCode::AboutMetricParts,
            'label' => fake()->optional()->words(2, true),
            'title' => fake()->sentence(3),
            'text' => fake()->optional()->paragraph(),
            'is_active' => true,
            'position' => 0,
        ];
    }
}
