<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        $title = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'title' => Str::title($title),
            'slug' => Str::slug($title),
            'position' => fake()->numberBetween(0, 500),
            'is_active' => true,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function forParent(ProductCategory $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }
}
