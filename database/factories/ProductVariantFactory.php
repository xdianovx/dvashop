<?php

namespace Database\Factories;

use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => fake()->unique()->optional()->bothify('VAR-#####'),
            'title' => fake()->optional()->randomElement(['Стандарт', 'Комплект левый/правый', 'Усиленная версия']),
            'options' => null,
            'price' => fake()->randomFloat(2, 1000, 50000),
            'old_price' => null,
            'stock_quantity' => fake()->optional()->numberBetween(0, 50),
            'stock_status' => StockStatus::InStock,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
