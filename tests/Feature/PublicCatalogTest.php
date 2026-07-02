<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

function publicCatalogTree(): array
{
    $make = VehicleMake::factory()->create([
        'title' => 'Toyota',
        'slug' => 'toyota',
        'norm_key' => 'toyota',
        'is_active' => true,
    ]);

    $model = VehicleModel::factory()->forMake($make)->create([
        'title' => 'Camry',
        'slug' => 'camry',
        'norm_key' => 'camry',
        'is_active' => true,
    ]);

    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'XV70',
        'slug' => 'xv70',
        'norm_key' => 'xv70',
        'years_label' => '2017-2023',
        'body' => 'седан',
        'is_active' => true,
    ]);

    return [$make, $model, $generation];
}

function publicCatalogProduct(array $productState = []): array
{
    [$make, $model, $generation] = publicCatalogTree();

    $category = ProductCategory::factory()->create([
        'title' => 'Пороги',
        'slug' => 'porogi',
        'is_active' => true,
    ]);

    $product = Product::factory()->forCategory($category)->create(array_merge([
        'title' => 'Порог для Toyota Camry XV70',
        'slug' => 'porog-toyota-camry-xv70',
        'sku' => 'PROD-TOYOTA-CAMRY-XV70',
        'status' => ProductStatus::Active,
        'description' => 'Описание порога Toyota Camry',
    ], $productState));

    $variant = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'VAR-TOYOTA-CAMRY-XV70',
        'price' => 2500,
        'old_price' => 3000,
        'is_active' => true,
    ]);

    ProductImage::factory()->forProduct($product)->main()->create([
        'path' => '/img/products/threshold.png',
        'alt' => 'Порог Toyota Camry',
    ]);

    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->primary()->create();

    return [$make, $model, $generation, $category, $product, $variant];
}

test('catalog page shows active vehicle makes from database', function () {
    VehicleMake::factory()->create([
        'title' => 'Toyota',
        'slug' => 'toyota',
        'norm_key' => 'toyota',
        'is_active' => true,
    ]);

    VehicleMake::factory()->inactive()->create([
        'title' => 'Hidden Brand',
        'slug' => 'hidden-brand',
        'norm_key' => 'hidden-brand',
    ]);

    $this->get('/catalog')
        ->assertOk()
        ->assertSee('Toyota')
        ->assertDontSee('Hidden Brand');
});

test('make catalog page shows active models from database', function () {
    [$make] = publicCatalogTree();

    VehicleModel::factory()->forMake($make)->inactive()->create([
        'title' => 'Hidden Model',
        'slug' => 'hidden-model',
        'norm_key' => 'hidden-model',
    ]);

    $this->get(route('catalog.make', $make->slug))
        ->assertOk()
        ->assertSee('Camry')
        ->assertDontSee('Hidden Model');
});

test('generation catalog page shows active products with default variant', function () {
    [$make, $model, $generation, $category, $product] = publicCatalogProduct();

    Product::factory()->forCategory($category)->archived()->create([
        'title' => 'Скрытый порог',
        'slug' => 'hidden-porog',
    ]);

    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))
        ->assertOk()
        ->assertSee($product->title)
        ->assertDontSee('Скрытый порог')
        ->assertSee(route('products.show', $product->slug));
});

test('product page opens with real product data and cart form', function () {
    [, , , , $product, $variant] = publicCatalogProduct();

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee($product->title)
        ->assertSee($variant->sku)
        ->assertSee('2 500 руб.')
        ->assertSee(route('cart.items.store'))
        ->assertSee('product_variant_id')
        ->assertSee((string) $variant->getKey());
});

test('inactive product page does not open', function () {
    [, , , , $product] = publicCatalogProduct([
        'status' => ProductStatus::Archived,
    ]);

    $this->get(route('products.show', $product->slug))->assertNotFound();
});

test('catalog generation page does not add N plus one queries for product cards', function () {
    [$make, $model, $generation, $category] = publicCatalogProduct();

    for ($i = 0; $i < 5; $i++) {
        $product = Product::factory()->forCategory($category)->create([
            'title' => 'Порог дополнительный '.$i,
            'slug' => 'porog-extra-'.$i,
            'status' => ProductStatus::Active,
        ]);

        ProductVariant::factory()->forProduct($product)->default()->create(['is_active' => true, 'price' => 2000 + $i]);
        ProductImage::factory()->forProduct($product)->main()->create(['path' => '/img/products/threshold.png']);
        ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))->assertOk();

    expect($queries)->toBeLessThanOrEqual(18);
});
