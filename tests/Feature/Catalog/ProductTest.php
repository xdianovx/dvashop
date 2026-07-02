<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product can be created with category and base fields', function () {
    $category = ProductCategory::factory()->create(['title' => 'Пороги', 'slug' => 'porogi']);

    $product = Product::factory()->forCategory($category)->create([
        'title' => 'Порог левый Toyota Camry',
        'slug' => 'toyota-camry-left-threshold',
        'sku' => 'CAMRY-L-001',
    ]);

    expect($product->fresh())
        ->title->toBe('Порог левый Toyota Camry')
        ->slug->toBe('toyota-camry-left-threshold')
        ->sku->toBe('CAMRY-L-001')
        ->and($product->category->is($category))->toBeTrue();
});

test('product slug and import key are unique', function () {
    Product::factory()->create(['slug' => 'unique-product', 'import_key' => 'import-1']);

    Product::factory()->create(['slug' => 'unique-product', 'import_key' => 'import-2']);
})->throws(QueryException::class);

test('first variant becomes default and only one default variant remains', function () {
    $product = Product::factory()->create();

    $first = ProductVariant::factory()->forProduct($product)->create(['is_default' => false]);
    $second = ProductVariant::factory()->forProduct($product)->default()->create();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($product->fresh()->defaultVariant->is($second))->toBeTrue()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

test('variant sku is unique', function () {
    ProductVariant::factory()->create(['sku' => 'VAR-UNIQUE']);

    ProductVariant::factory()->create(['sku' => 'VAR-UNIQUE']);
})->throws(QueryException::class);

test('product has images and vehicle generation fitments', function () {
    $category = ProductCategory::factory()->create();
    $generation = VehicleGeneration::factory()->create();
    $product = Product::factory()->forCategory($category)->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create();

    ProductImage::factory()->forVariant($variant)->main()->create([
        'path' => 'products/test-image.jpg',
    ]);

    ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration($generation)
        ->primary()
        ->create();

    expect($product->fresh())
        ->images->toHaveCount(1)
        ->fitments->toHaveCount(1)
        ->and($product->category->is($category))->toBeTrue()
        ->and($product->vehicleGenerations()->first()->is($generation))->toBeTrue()
        ->and($generation->fresh()->products()->first()->is($product))->toBeTrue();
});

test('product fitment is unique per vehicle generation', function () {
    $product = Product::factory()->create();
    $generation = VehicleGeneration::factory()->create();

    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
})->throws(QueryException::class);
