<?php

namespace Database\Factories;

use App\Models\VehicleMake;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleMake>
 */
class VehicleMakeFactory extends Factory
{
    protected $model = VehicleMake::class;

    public function definition(): array
    {
        $title = fake()->unique()->company();

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'norm_key' => Str::slug($title),
            'image' => null,
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
}
