<?php

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\DefaultProductImageService;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\VehicleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product seeder creates a valid auto part demo product with a real default image', function () {
    $this->seed([
        ProductCatalogSeeder::class,
        PartTypeSeeder::class,
        ProductOptionSeeder::class,
        VehicleCatalogSeeder::class,
        ProductSeeder::class,
    ]);

    $product = Product::query()
        ->with(['partType', 'category', 'images'])
        ->where('slug', 'demo-porogi-toyota-camry')
        ->firstOrFail();
    $defaultImage = $product->images->firstWhere('source_type', ProductImage::SOURCE_DEFAULT);

    expect($product->product_type)->toBe(ProductType::AutoPart)
        ->and($product->partType?->full_slug)->toBe('porog')
        ->and($product->category?->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova/porogi')
        ->and($product->optionTemplate?->slug)->toBe('default_auto_part')
        ->and($product->images->contains('path', 'products/demo-porogi-camry.jpg'))->toBeFalse()
        ->and($defaultImage)->toBeInstanceOf(ProductImage::class)
        ->and($defaultImage?->is_default)->toBeTrue()
        ->and($defaultImage?->is_visible)->toBeTrue()
        ->and(is_file(public_path((string) $defaultImage?->path)))->toBeTrue()
        ->and(str_starts_with((string) $defaultImage?->path, DefaultProductImageService::DIRECTORY.'/'))->toBeTrue();
});
