<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCharacteristic;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductCharacteristic> */
class ProductCharacteristicFactory extends Factory
{
    protected $model = ProductCharacteristic::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->words(2, true),
            'value' => fake()->word(),
            'unit' => null,
            'source_type' => ProductCharacteristic::SOURCE_MANUAL,
            'is_visible' => true,
            'position' => 0,
        ];
    }
}
