<?php

namespace Database\Factories;

use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleGeneration>
 */
class VehicleGenerationFactory extends Factory
{
    protected $model = VehicleGeneration::class;

    public function definition(): array
    {
        $title = fake()->unique()->bothify('Generation #??');

        return [
            'vehicle_model_id' => VehicleModel::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'norm_key' => Str::slug($title),
            'years_label' => fake()->optional()->randomElement(['2018–2021', '2021–н.в.', '2015–2020']),
            'body' => fake()->optional()->randomElement(['sedan', 'hatchback', 'wagon', 'suv']),
            'image' => null,
            'image_source_url' => null,
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

    public function forVehicleModel(VehicleModel $model): static
    {
        return $this->state(fn (): array => ['vehicle_model_id' => $model->getKey()]);
    }
}
