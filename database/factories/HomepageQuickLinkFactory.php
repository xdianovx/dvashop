<?php

namespace Database\Factories;

use App\Enums\HomepageQuickLinkCode;
use App\Models\HomepageQuickLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomepageQuickLink> */
class HomepageQuickLinkFactory extends Factory
{
    protected $model = HomepageQuickLink::class;

    public function definition(): array
    {
        return [
            'code' => fake()->randomElement(HomepageQuickLinkCode::cases()),
            'title' => fake()->words(2, true),
            'link_type' => null,
            'route_name' => null,
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
