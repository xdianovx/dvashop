<?php

namespace Database\Factories;

use App\Enums\HomepageCategoryCardCode;
use App\Enums\NavigationLinkType;
use App\Models\HomepageCategoryCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomepageCategoryCard> */
class HomepageCategoryCardFactory extends Factory
{
    protected $model = HomepageCategoryCard::class;

    public function definition(): array
    {
        return [
            'code' => fake()->randomElement(HomepageCategoryCardCode::cases()),
            'title' => fake()->words(2, true),
            'link_type' => NavigationLinkType::Route,
            'route_name' => 'catalog.index',
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
