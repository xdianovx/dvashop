<?php

namespace Database\Factories;

use App\Models\HomepageStoryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomepageStoryGroup> */
class HomepageStoryGroupFactory extends Factory
{
    protected $model = HomepageStoryGroup::class;

    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
            'cover_image_path' => 'uploads/homepage/stories/test-cover.webp',
            'is_active' => true,
            'position' => 0,
        ];
    }
}
