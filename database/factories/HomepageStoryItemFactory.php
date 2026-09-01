<?php

namespace Database\Factories;

use App\Enums\HomepageStoryMediaType;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomepageStoryItem> */
class HomepageStoryItemFactory extends Factory
{
    protected $model = HomepageStoryItem::class;

    public function definition(): array
    {
        return [
            'homepage_story_group_id' => HomepageStoryGroup::factory(),
            'media_type' => HomepageStoryMediaType::Image,
            'media_path' => 'uploads/homepage/stories/test-story.webp',
            'alt_text' => fake()->sentence(4),
            'cta_label' => null,
            'cta_url' => null,
            'open_in_new_tab' => false,
            'duration_seconds' => 10,
            'is_active' => true,
            'position' => 0,
        ];
    }
}
