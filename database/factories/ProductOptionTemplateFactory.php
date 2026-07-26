<?php

namespace Database\Factories;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductOptionTemplate> */
class ProductOptionTemplateFactory extends Factory
{
    protected $model = ProductOptionTemplate::class;

    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.strtolower((string) Str::ulid()),
            'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
            'part_type_id' => null,
            'is_default' => false,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
