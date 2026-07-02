<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\VehicleGeneration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductFitment>
 */
class ProductFitmentFactory extends Factory
{
    protected $model = ProductFitment::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'vehicle_generation_id' => VehicleGeneration::factory(),
            'note' => fake()->optional()->sentence(4),
            'is_primary' => false,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }

    public function forVehicleGeneration(VehicleGeneration $generation): static
    {
        return $this->state(fn (): array => ['vehicle_generation_id' => $generation->getKey()]);
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }
}
