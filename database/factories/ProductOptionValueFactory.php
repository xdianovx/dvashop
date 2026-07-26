<?php

namespace Database\Factories;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductOptionValue> */
class ProductOptionValueFactory extends Factory
{
    protected $model = ProductOptionValue::class;

    public function definition(): array
    {
        $title = fake()->unique()->word();
        $slug = Str::slug($title).'-'.strtolower((string) Str::ulid());

        return [
            'product_option_group_id' => ProductOptionGroup::factory(),
            'title' => $title,
            'slug' => $slug,
            'code' => $slug,
            'is_default' => false,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function forGroup(ProductOptionGroup $group): static
    {
        return $this->state(fn (): array => ['product_option_group_id' => $group->getKey()]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
