<?php

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Services\Media\DefaultProductImageService;
use App\Services\Media\ProductGalleryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function galleryTestCategory(string $title = 'Порог'): ProductCategory
{
    $existing = ProductCategory::query()
        ->whereNull('parent_id')
        ->where('title', $title)
        ->first();

    if ($existing instanceof ProductCategory) {
        return $existing->refresh();
    }

    return ProductCategory::factory()->create([
        'title' => $title,
        'slug' => null,
    ])->refresh();
}

function galleryTestProduct(?ProductCategory $category = null): Product
{
    return Product::factory()->forCategory($category ?: galleryTestCategory())->create();
}

function putManualGallerySource(Product $product, string $name = 'source.jpg'): string
{
    $path = 'uploads/products/manual/'.$product->getKey().'/'.$name;
    Storage::disk('public')->put($path, test_image_binary('jpeg', 80, 60));

    return $path;
}

test('product can have multiple images and choose one visible main image', function () {
    $product = galleryTestProduct();

    $first = ProductImage::factory()->forProduct($product)->create([
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_main' => true,
        'is_visible' => true,
        'checksum' => str_repeat('1', 64),
    ]);
    $second = ProductImage::factory()->forProduct($product)->create([
        'source_type' => ProductImage::SOURCE_IMPORT,
        'is_main' => false,
        'is_visible' => true,
        'checksum' => str_repeat('2', 64),
    ]);
    $third = ProductImage::factory()->forProduct($product)->create([
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_main' => false,
        'is_visible' => true,
        'checksum' => str_repeat('3', 64),
    ]);

    app(ProductGalleryService::class)->makeMain($second);

    expect($product->images()->count())->toBe(3)
        ->and($first->fresh()->is_main)->toBeFalse()
        ->and($second->fresh()->is_main)->toBeTrue()
        ->and($second->fresh()->is_visible)->toBeTrue()
        ->and($third->fresh()->is_main)->toBeFalse()
        ->and($product->images()->where('is_main', true)->count())->toBe(1);
});

test('manual gallery upload is processed to webp and marked as manual', function () {
    $product = galleryTestProduct();
    $sourcePath = putManualGallerySource($product);

    $image = app(ProductGalleryService::class)->attachManualImage($product, $sourcePath, 'Manual alt');

    expect($image->source_type)->toBe(ProductImage::SOURCE_MANUAL)
        ->and($image->is_default)->toBeFalse()
        ->and($image->is_visible)->toBeTrue()
        ->and($image->is_main)->toBeTrue()
        ->and($image->alt)->toBe('Manual alt')
        ->and($image->mime)->toBe('image/webp')
        ->and($image->path)->toStartWith('uploads/products/'.$product->getKey().'/')
        ->and(Storage::disk('public')->exists($image->path))->toBeTrue()
        ->and(Storage::disk('public')->exists($sourcePath))->toBeFalse();
});

test('import image source type remains import and can be selected as main', function () {
    $product = galleryTestProduct();
    $path = 'uploads/products/'.$product->getKey().'/import.webp';
    Storage::disk('public')->put($path, test_image_binary('webp'));

    $image = ProductImage::factory()->forProduct($product)->create([
        'disk' => 'public',
        'path' => $path,
        'source_type' => ProductImage::SOURCE_IMPORT,
        'is_main' => false,
        'is_visible' => true,
        'mime' => 'image/webp',
        'checksum' => str_repeat('4', 64),
    ]);

    app(ProductGalleryService::class)->makeMain($image);

    expect($image->fresh()->source_type)->toBe(ProductImage::SOURCE_IMPORT)
        ->and($image->fresh()->is_main)->toBeTrue()
        ->and($product->fresh()->main_image_url)->toContain('/storage/'.$path);
});

test('default image can become main without deleting manual and import images', function () {
    $product = galleryTestProduct(galleryTestCategory('Порог'));
    $manual = ProductImage::factory()->forProduct($product)->main()->create([
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
        'checksum' => str_repeat('5', 64),
    ]);
    $import = ProductImage::factory()->forProduct($product)->create([
        'source_type' => ProductImage::SOURCE_IMPORT,
        'is_visible' => true,
        'checksum' => str_repeat('6', 64),
    ]);

    $default = app(ProductGalleryService::class)->makeDefaultMain($product);

    expect($default->source_type)->toBe(ProductImage::SOURCE_DEFAULT)
        ->and($default->is_default)->toBeTrue()
        ->and($default->is_main)->toBeTrue()
        ->and($default->is_visible)->toBeTrue()
        ->and($manual->fresh()->exists)->toBeTrue()
        ->and($import->fresh()->exists)->toBeTrue()
        ->and($manual->fresh()->is_main)->toBeFalse()
        ->and($product->images()->count())->toBe(3);
});

test('reset gallery to default deletes manual and import files but keeps public default file', function () {
    $product = galleryTestProduct(galleryTestCategory('Порог'));
    $manualPath = 'uploads/products/'.$product->getKey().'/manual.webp';
    $importPath = 'uploads/products/'.$product->getKey().'/import.webp';
    Storage::disk('public')->put($manualPath, test_image_binary('webp'));
    Storage::disk('public')->put($importPath, test_image_binary('webp'));

    ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => 'public',
        'path' => $manualPath,
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
        'mime' => 'image/webp',
        'checksum' => str_repeat('7', 64),
    ]);
    ProductImage::factory()->forProduct($product)->create([
        'disk' => 'public',
        'path' => $importPath,
        'source_type' => ProductImage::SOURCE_IMPORT,
        'is_visible' => true,
        'mime' => 'image/webp',
        'checksum' => str_repeat('8', 64),
    ]);

    $defaultPath = app(DefaultProductImageService::class)->forCategory($product->category)['absolute_path'];
    $default = app(ProductGalleryService::class)->resetToDefault($product);

    expect(Storage::disk('public')->exists($manualPath))->toBeFalse()
        ->and(Storage::disk('public')->exists($importPath))->toBeFalse()
        ->and(is_file($defaultPath))->toBeTrue()
        ->and($default->source_type)->toBe(ProductImage::SOURCE_DEFAULT)
        ->and($default->is_main)->toBeTrue()
        ->and($default->is_visible)->toBeTrue()
        ->and($product->images()->count())->toBe(1);
});

test('reset gallery to default does not delete anything when default image is missing', function () {
    $product = galleryTestProduct(ProductCategory::factory()->create(['title' => 'Неизвестная деталь', 'slug' => 'unknown-detail']));
    $path = 'uploads/products/'.$product->getKey().'/manual.webp';
    Storage::disk('public')->put($path, test_image_binary('webp'));

    $image = ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => 'public',
        'path' => $path,
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
        'mime' => 'image/webp',
        'checksum' => str_repeat('9', 64),
    ]);

    expect(fn () => app(ProductGalleryService::class)->resetToDefault($product))->toThrow(RuntimeException::class)
        ->and($image->fresh()->exists)->toBeTrue()
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

test('main image cannot be hidden through gallery service', function () {
    $product = galleryTestProduct();
    $image = ProductImage::factory()->forProduct($product)->main()->create([
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
        'checksum' => str_repeat('a', 64),
    ]);

    expect(fn () => app(ProductGalleryService::class)->setVisible($image, false))->toThrow(LogicException::class)
        ->and($image->fresh()->is_visible)->toBeTrue()
        ->and($image->fresh()->is_main)->toBeTrue();
});

test('deleting main image promotes another visible image or leaves product without main', function () {
    $product = galleryTestProduct();
    $main = ProductImage::factory()->forProduct($product)->main()->create([
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
        'position' => 1,
        'checksum' => str_repeat('b', 64),
    ]);
    $candidate = ProductImage::factory()->forProduct($product)->create([
        'source_type' => ProductImage::SOURCE_IMPORT,
        'is_visible' => true,
        'position' => 2,
        'checksum' => str_repeat('c', 64),
    ]);

    app(ProductGalleryService::class)->deleteImage($main);

    expect($candidate->fresh()->is_main)->toBeTrue()
        ->and($product->images()->where('is_main', true)->count())->toBe(1);

    app(ProductGalleryService::class)->deleteImage($candidate->fresh());

    expect($product->images()->where('is_main', true)->count())->toBe(0)
        ->and($product->fresh()->main_image_url)->not->toBe('');
});

test('ProductResource image filters work at query level', function () {
    $without = galleryTestProduct();
    $manual = galleryTestProduct();
    $import = galleryTestProduct();
    $default = galleryTestProduct(galleryTestCategory('Порог'));

    ProductImage::factory()->forProduct($manual)->create([
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
        'checksum' => str_repeat('d', 64),
    ]);
    ProductImage::factory()->forProduct($import)->create([
        'source_type' => ProductImage::SOURCE_IMPORT,
        'is_visible' => true,
        'checksum' => str_repeat('e', 64),
    ]);
    app(ProductGalleryService::class)->ensureDefaultImage($default);

    expect(ProductResource::applyWithoutVisibleImagesFilter(Product::query())->pluck('id')->all())->toContain($without->getKey())
        ->and(ProductResource::applyImageSourceFilter(Product::query(), ProductImage::SOURCE_MANUAL)->pluck('id')->all())->toBe([$manual->getKey()])
        ->and(ProductResource::applyImageSourceFilter(Product::query(), ProductImage::SOURCE_IMPORT)->pluck('id')->all())->toBe([$import->getKey()])
        ->and(ProductResource::applyImageSourceFilter(Product::query(), ProductImage::SOURCE_DEFAULT)->pluck('id')->all())->toBe([$default->getKey()]);
});
