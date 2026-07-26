<?php

namespace Database\Factories;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariantOptionValue> */
class ProductVariantOptionValueFactory extends Factory
{
    protected $model = ProductVariantOptionValue::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'product_option_group_id' => ProductOptionGroup::factory(),
            'product_option_value_id' => fn (array $attributes): int => ProductOptionValue::factory()->create([
                'product_option_group_id' => $attributes['product_option_group_id'],
            ])->getKey(),
        ];
    }
}
