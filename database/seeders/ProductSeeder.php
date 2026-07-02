<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $category = ProductCategory::query()->where('slug', 'porogi')->first()
            ?? ProductCategory::query()->orderBy('id')->first();

        $generation = VehicleGeneration::query()->with('model.make')->orderBy('id')->first();

        $product = Product::query()->firstOrCreate(
            ['slug' => 'demo-porogi-toyota-camry'],
            [
                'product_category_id' => $category?->getKey(),
                'title' => 'Демо пороги Toyota Camry',
                'sku' => 'DEMO-POROGI-CAMRY',
                'status' => 'active',
                'short_description' => 'Демонстрационный товар для проверки структуры каталога.',
                'price' => 12500,
                'stock_status' => 'in_stock',
                'position' => 10,
                'is_featured' => true,
            ],
        );

        ProductVariant::query()->firstOrCreate(
            ['sku' => 'DEMO-POROGI-CAMRY-BASE'],
            [
                'product_id' => $product->getKey(),
                'title' => 'Базовый комплект',
                'price' => 12500,
                'stock_status' => 'in_stock',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        ProductImage::query()->firstOrCreate(
            ['product_id' => $product->getKey(), 'path' => 'products/demo-porogi-camry.jpg'],
            [
                'alt' => 'Демо пороги Toyota Camry',
                'position' => 0,
                'is_main' => true,
            ],
        );

        if ($generation) {
            ProductFitment::query()->firstOrCreate(
                ['product_id' => $product->getKey(), 'vehicle_generation_id' => $generation->getKey()],
                ['note' => 'Проверочная применимость', 'is_primary' => true],
            );
        }
    }
}
