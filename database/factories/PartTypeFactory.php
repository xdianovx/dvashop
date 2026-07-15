<?php

namespace Database\Factories;

use App\Models\PartType;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartType>
 */
class PartTypeFactory extends Factory
{
    protected $model = PartType::class;

    public function definition(): array
    {
        return [
            'parent_id' => null,
            'title' => fake()->unique()->words(2, true),
            'position' => fake()->numberBetween(0, 500),
            'is_active' => true,
            'default_image_key' => null,
            'product_category_id' => null,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function root(): static
    {
        return $this->state(fn (): array => ['parent_id' => null]);
    }

    public function childOf(PartType $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function forCategory(ProductCategory $category): static
    {
        return $this->state(fn (): array => ['product_category_id' => $category->getKey()]);
    }
}
