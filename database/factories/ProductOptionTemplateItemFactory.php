<?php

namespace Database\Factories;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductOptionTemplateItem> */
class ProductOptionTemplateItemFactory extends Factory
{
    protected $model = ProductOptionTemplateItem::class;

    public function definition(): array
    {
        return [
            'product_option_template_id' => ProductOptionTemplate::factory(),
            'product_option_group_id' => ProductOptionGroup::factory(),
            'product_option_value_id' => fn (array $attributes): int => ProductOptionValue::factory()->create([
                'product_option_group_id' => $attributes['product_option_group_id'],
            ])->getKey(),
            'position' => 0,
        ];
    }
}
