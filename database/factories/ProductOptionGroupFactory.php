<?php

namespace Database\Factories;

use App\Models\ProductOptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductOptionGroup> */
class ProductOptionGroupFactory extends Factory
{
    protected $model = ProductOptionGroup::class;

    public function definition(): array
    {
        $title = fake()->unique()->words(2, true);
        $slug = Str::slug($title).'-'.strtolower((string) Str::ulid());

        return [
            'title' => $title,
            'slug' => $slug,
            'code' => $slug,
            'input_type' => 'radio',
            'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
            'is_required' => false,
            'is_active' => true,
            'position' => 0,
        ];
    }
}
