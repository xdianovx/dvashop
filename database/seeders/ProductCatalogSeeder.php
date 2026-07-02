<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $exterior = ProductCategory::query()->firstOrCreate(
            ['full_slug' => 'kuzovnye-detali'],
            ['title' => 'Кузовные детали', 'slug' => 'kuzovnye-detali', 'position' => 10, 'is_active' => true],
        );

        ProductCategory::query()->firstOrCreate(
            ['full_slug' => 'kuzovnye-detali/porogi'],
            ['parent_id' => $exterior->getKey(), 'title' => 'Пороги', 'slug' => 'porogi', 'position' => 10, 'is_active' => true],
        );

        ProductCategory::query()->firstOrCreate(
            ['full_slug' => 'kuzovnye-detali/arki'],
            ['parent_id' => $exterior->getKey(), 'title' => 'Арки', 'slug' => 'arki', 'position' => 20, 'is_active' => true],
        );
    }
}
