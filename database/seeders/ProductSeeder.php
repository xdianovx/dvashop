<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Media\ProductGalleryService;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(ProductGalleryService $gallery): void
    {
        $partType = PartType::query()
            ->with('productCategory')
            ->where('full_slug', 'porog')
            ->firstOrFail();

        $category = $partType->productCategory
            ?? ProductCategory::query()
                ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova/porogi')
                ->firstOrFail();

        $generation = VehicleGeneration::query()->with('model.make')->orderBy('id')->first();
        $optionTemplate = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->first();

        $product = Product::query()->updateOrCreate(
            ['slug' => 'demo-porogi-toyota-camry'],
            [
                'product_category_id' => $category->getKey(),
                'product_type' => ProductType::AutoPart,
                'part_type_id' => $partType->getKey(),
                'product_option_template_id' => $optionTemplate?->getKey(),
                'title' => 'Демо пороги Toyota Camry',
                'sku' => 'DEMO-POROGI-CAMRY',
                'status' => ProductStatus::Active,
                'short_description' => 'Демонстрационный товар для проверки структуры каталога.',
                'price' => 12500,
                'stock_status' => StockStatus::InStock,
                'position' => 10,
                'is_featured' => true,
            ],
        );

        ProductVariant::query()->updateOrCreate(
            ['sku' => 'DEMO-POROGI-CAMRY-BASE'],
            [
                'product_id' => $product->getKey(),
                'title' => 'Базовый комплект',
                'price' => 12500,
                'stock_status' => StockStatus::InStock,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $product->images()
            ->where('path', 'products/demo-porogi-camry.jpg')
            ->get()
            ->each->delete();

        $gallery->ensureDefaultImage($product->refresh());

        if ($generation) {
            ProductFitment::query()->firstOrCreate(
                ['product_id' => $product->getKey(), 'vehicle_generation_id' => $generation->getKey()],
                ['note' => 'Проверочная применимость', 'is_primary' => true],
            );
        }
    }
}
