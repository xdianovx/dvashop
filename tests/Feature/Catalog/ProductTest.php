<?php

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product type enum has human readable labels', function () {
    expect(ProductType::AutoPart->label())->toBe('Автодеталь')
        ->and(ProductType::Generic->label())->toBe('Обычный товар');
});

test('product stores and casts both product types', function (ProductType $type, bool $isAutoPart, bool $isGeneric) {
    $product = Product::factory()->create(['product_type' => $type]);
    $freshProduct = $product->fresh();

    expect($freshProduct->product_type)->toBe($type)
        ->and($freshProduct->isAutoPart())->toBe($isAutoPart)
        ->and($freshProduct->isGeneric())->toBe($isGeneric);
})->with([
    'auto part' => [ProductType::AutoPart, true, false],
    'generic product' => [ProductType::Generic, false, true],
]);

test('database defaults product type to auto part without changing existing fields', function () {
    $product = Product::query()->create([
        'title' => 'Герметик кузовной 310 мл',
        'slug' => 'germetik-kuzovnoi-310-ml',
        'sku' => 'SEALANT-310',
        'price' => 890,
    ])->fresh();

    expect($product)
        ->product_type->toBe(ProductType::AutoPart)
        ->title->toBe('Герметик кузовной 310 мл')
        ->slug->toBe('germetik-kuzovnoi-310-ml')
        ->sku->toBe('SEALANT-310')
        ->price->toBe('890.00')
        ->and($product->isAutoPart())->toBeTrue();
});

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

test('product factory generates unique slugs and non-null skus without faker unique cache', function () {
    $category = ProductCategory::factory()->create();

    Product::factory()
        ->count(500)
        ->forCategory($category)
        ->create();

    $productsCount = Product::query()->count();
    $distinctSlugsCount = Product::query()->distinct()->count('slug');
    $skuCount = Product::query()->whereNotNull('sku')->count();
    $distinctSkuCount = Product::query()
        ->whereNotNull('sku')
        ->distinct()
        ->count('sku');

    expect($productsCount)->toBe(500)
        ->and($distinctSlugsCount)->toBe(500)
        ->and($distinctSkuCount)->toBe($skuCount);
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
