<?php

namespace Database\Factories;

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use App\Models\SiteNavigationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SiteNavigationItem> */
class SiteNavigationItemFactory extends Factory
{
    protected $model = SiteNavigationItem::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'zone' => NavigationZone::HeaderTop,
            'title' => fake()->words(2, true),
            'link_type' => NavigationLinkType::Route,
            'route_name' => 'about',
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
