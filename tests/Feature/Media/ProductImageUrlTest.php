<?php

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\VehicleGenerations\VehicleGenerationResource;
use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('ProductImage on public disk returns product main_image_url from storage', function () {
    $product = Product::factory()->create();
    $path = 'uploads/products/'.$product->getKey().'/main.webp';

    Storage::disk('public')->put($path, test_image_binary('webp', 40, 30));

    ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => 'public',
        'path' => $path,
        'mime' => 'image/webp',
        'checksum' => hash('sha256', Storage::disk('public')->get($path)),
    ]);

    expect($product->fresh()->main_image_url)
        ->not->toBe('')
        ->toContain('/storage/'.$path);
});

test('Product without image returns placeholder image url', function () {
    $product = Product::factory()->create(['product_category_id' => null]);

    expect($product->main_image_url)
        ->not->toBe('')
        ->toContain('img/placeholders/image.svg');
});

test('Product without uploaded image can use default product image by category', function () {
    $category = ProductCategory::factory()->create(['title' => 'Порог', 'slug' => 'porog']);
    $product = Product::factory()->forCategory($category)->create();

    expect($product->main_image_url)
        ->not->toBe('')
        ->toContain('img/products_default/porog.png');
});

test('Product with missing image path returns placeholder image url', function () {
    $product = Product::factory()->create(['product_category_id' => null]);

    ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => 'public',
        'path' => 'uploads/products/'.$product->getKey().'/missing.webp',
        'mime' => 'image/webp',
        'checksum' => str_repeat('a', 64),
    ]);

    expect($product->fresh()->main_image_url)
        ->not->toBe('')
        ->toContain('img/placeholders/image.svg');
});

test('VehicleGeneration with public image returns image_url from storage', function () {
    $generation = VehicleGeneration::factory()->create();
    $path = 'uploads/vehicles/generations/'.$generation->getKey().'/main.webp';

    Storage::disk('public')->put($path, test_image_binary('webp', 80, 40));
    $generation->forceFill(['image' => $path])->save();

    expect($generation->fresh()->image_url)
        ->not->toBe('')
        ->toContain('/storage/'.$path);
});

test('VehicleGeneration without image returns placeholder image url', function () {
    $generation = VehicleGeneration::factory()->create(['image' => null]);

    expect($generation->image_url)
        ->not->toBe('')
        ->toContain('img/placeholders/image.svg');
});

test('VehicleMake with public image returns image_url from storage', function () {
    $make = VehicleMake::factory()->create();
    $path = 'uploads/vehicles/makes/'.$make->getKey().'/main.webp';

    Storage::disk('public')->put($path, test_image_binary('webp', 80, 80));
    $make->forceFill(['image' => $path])->save();

    expect($make->fresh()->image_url)
        ->not->toBe('')
        ->toContain('/storage/'.$path);
});

test('ProductResource and vehicle Filament image states never return empty urls', function () {
    $product = Product::factory()->create(['product_category_id' => null]);
    $generation = VehicleGeneration::factory()->create(['image' => null]);
    $make = VehicleMake::factory()->create(['image' => null]);

    expect(ProductResource::getModel())->toBe(Product::class)
        ->and(VehicleGenerationResource::getModel())->toBe(VehicleGeneration::class)
        ->and(VehicleMakeResource::getModel())->toBe(VehicleMake::class)
        ->and($product->main_image_url)->not->toBe('')
        ->and($generation->image_url)->not->toBe('')
        ->and($make->image_url)->not->toBe('');
});

test('Product cannot keep multiple visible main images after saving ProductImage', function () {
    $product = Product::factory()->create();

    $first = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'uploads/products/'.$product->getKey().'/first.webp',
        'mime' => 'image/webp',
        'checksum' => str_repeat('b', 64),
    ]);

    $second = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'uploads/products/'.$product->getKey().'/second.webp',
        'mime' => 'image/webp',
        'checksum' => str_repeat('c', 64),
        'is_visible' => false,
    ]);

    expect($first->fresh()->is_main)->toBeFalse()
        ->and($second->fresh()->is_main)->toBeTrue()
        ->and($second->fresh()->is_visible)->toBeTrue()
        ->and($product->images()->where('is_main', true)->count())->toBe(1);
});

test('ProductResource exposes full category path for table display', function () {
    $parent = ProductCategory::factory()->create(['title' => 'Арка', 'slug' => 'arka']);
    $child = ProductCategory::factory()->create([
        'parent_id' => $parent->getKey(),
        'title' => 'Внутренняя универсальная',
        'slug' => 'vnutrennyaya-universalnaya',
    ]);
    $product = Product::factory()->create(['product_category_id' => $child->getKey()]);

    $record = ProductResource::getEloquentQuery()->whereKey($product->getKey())->firstOrFail();

    expect($record->category?->full_title)->toBe('Арка / Внутренняя универсальная');
});
