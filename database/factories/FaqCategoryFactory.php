<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FaqCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'faq_category_'.Str::lower((string) Str::ulid()),
            'title' => fake()->words(3, true),
            'is_active' => true,
            'position' => 0,
        ];
    }
}
