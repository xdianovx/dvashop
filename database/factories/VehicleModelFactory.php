<?php

namespace Database\Factories;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleModel>
 */
class VehicleModelFactory extends Factory
{
    protected $model = VehicleModel::class;

    public function definition(): array
    {
        $title = fake()->unique()->bothify('Model ##??');

        return [
            'vehicle_make_id' => VehicleMake::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'norm_key' => Str::slug($title),
            'position' => fake()->numberBetween(0, 500),
            'is_active' => true,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function forMake(VehicleMake $make): static
    {
        return $this->state(fn (): array => ['vehicle_make_id' => $make->getKey()]);
    }
}
