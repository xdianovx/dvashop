<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $title = fake()->unique()->bothify('Порог кузовной ##??');

        return [
            'product_category_id' => ProductCategory::factory(),
            'product_type' => ProductType::AutoPart,
            'part_type_id' => null,
            'title' => $title,
            'slug' => Str::slug($title),
            'sku' => fake()->unique()->optional()->bothify('SKU-#####'),
            'status' => ProductStatus::Active,
            'short_description' => fake()->optional()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'price' => fake()->randomFloat(2, 1000, 50000),
            'old_price' => null,
            'stock_status' => StockStatus::InStock,
            'position' => fake()->numberBetween(0, 500),
            'is_featured' => false,
            'meta_title' => null,
            'meta_description' => null,
            'import_key' => null,
            'import_source' => null,
            'last_import_run_id' => null,
        ];
    }

    public function forCategory(ProductCategory $category): static
    {
        return $this->state(fn (): array => ['product_category_id' => $category->getKey()]);
    }

    public function forPartType(PartType $partType): static
    {
        return $this->state(fn (): array => [
            'product_type' => ProductType::AutoPart,
            'part_type_id' => $partType->getKey(),
        ]);
    }

    public function generic(): static
    {
        return $this->state(fn (): array => [
            'product_type' => ProductType::Generic,
            'part_type_id' => null,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Draft]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Archived]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    public function withDefaultVariant(): static
    {
        return $this->afterCreating(function (Product $product): void {
            ProductVariantFactory::new()
                ->forProduct($product)
                ->default()
                ->create([
                    'price' => $product->price ?? 0,
                    'old_price' => $product->old_price,
                    'stock_status' => $product->stock_status,
                ]);
        });
    }
}
