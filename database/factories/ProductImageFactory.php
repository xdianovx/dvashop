<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'disk' => 'public',
            'path' => 'products/'.fake()->uuid().'.jpg',
            'original_path' => null,
            'source_url' => null,
            'mime' => null,
            'width' => null,
            'height' => null,
            'size' => null,
            'checksum' => null,
            'conversions' => null,
            'alt' => fake()->optional()->sentence(3),
            'position' => fake()->numberBetween(0, 50),
            'is_main' => false,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (): array => [
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
        ]);
    }

    public function main(): static
    {
        return $this->state(fn (): array => ['is_main' => true]);
    }
}
