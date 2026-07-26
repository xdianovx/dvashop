<?php

namespace Database\Seeders;

use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

class VehicleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $make = VehicleMake::query()->firstOrCreate(
            ['norm_key' => 'lada'],
            ['title' => 'Lada', 'slug' => 'lada', 'position' => 10, 'is_active' => true],
        );

        $model = VehicleModel::query()->firstOrCreate(
            ['vehicle_make_id' => $make->getKey(), 'norm_key' => 'vesta'],
            ['title' => 'Vesta', 'slug' => 'vesta', 'position' => 10, 'is_active' => true],
        );

        VehicleGeneration::query()->updateOrCreate(
            ['vehicle_model_id' => $model->getKey(), 'norm_key' => 'i'],
            [
                'title' => 'I',
                'slug' => 'i',
                'years_label' => '2015–2022',
                'body' => 'sedan',
                'position' => 10,
                'is_active' => true,
            ],
        );

        VehicleGeneration::query()->updateOrCreate(
            ['vehicle_model_id' => $model->getKey(), 'norm_key' => 'ng'],
            [
                'title' => 'NG',
                'slug' => 'ng',
                'years_label' => '2022–н.в.',
                'body' => 'sedan',
                'position' => 20,
                'is_active' => true,
            ],
        );
    }
}
