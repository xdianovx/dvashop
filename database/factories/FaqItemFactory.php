<?php

namespace Database\Factories;

use App\Models\FaqCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FaqItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'faq_category_id' => FaqCategory::factory(),
            'code' => 'faq_item_'.Str::lower((string) Str::ulid()),
            'question' => fake()->sentence().'?',
            'answer' => fake()->paragraph(),
            'is_featured' => false,
            'is_active' => true,
            'position' => 0,
        ];
    }
}
