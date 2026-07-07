<?php

use App\Models\Product;
use App\Services\ImportStatusService;
use App\Services\ImportLogger;
use App\Models\ImportRun;
use App\Models\ImportLog;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Jobs\DownloadProductImageJob;
use App\Enums\ImportRunStatus;
use App\Enums\ImportLogLevel;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Services\Import\ImportImageDownloader;
use App\Services\Media\ImageDownloadService;
use App\Services\Media\ImageProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('non image binary is rejected and not stored', function () {
    expect(fn () => app(ImageProcessingService::class)->processBinary(
        contents: 'plain text payload',
        originalName: 'payload.txt',
        contentType: 'text/plain',
        profile: 'product_gallery',
        directory: 'uploads/products/1',
    ))->toThrow(InvalidArgumentException::class);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('html url is not saved as image', function () {
    Http::fake([
        'https://example.test/image.jpg' => Http::response('<html>not image</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn () => app(ImageDownloadService::class)->download(
        url: 'https://example.test/image.jpg',
        profile: 'product_gallery',
        directory: 'uploads/products/1',
    ))->toThrow(InvalidArgumentException::class);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('jpeg png and webp binaries are converted to real webp', function (string $format, string $mime) {
    $processed = app(ImageProcessingService::class)->processBinary(
        contents: test_image_binary($format, 80, 60),
        originalName: 'image.'.$format,
        contentType: $mime,
        profile: 'product_gallery',
        directory: 'uploads/products/1',
    );

    Storage::disk('public')->assertExists($processed->path);

    $stored = Storage::disk('public')->get($processed->path);

    expect($processed->mime)->toBe('image/webp')
        ->and($processed->path)->toEndWith('.webp')
        ->and(str_starts_with($stored, 'RIFF'))->toBeTrue()
        ->and(str_contains(substr($stored, 0, 16), 'WEBP'))->toBeTrue()
        ->and($processed->width)->toBe(80)
        ->and($processed->height)->toBe(60)
        ->and($processed->checksum)->toHaveLength(64)
        ->and($processed->conversions)->toHaveKeys(['thumb', 'card']);
})->with([
    ['jpeg', 'image/jpeg'],
    ['png', 'image/png'],
    ['webp', 'image/webp'],
]);

test('large image is resized without distortion', function () {
    $processed = app(ImageProcessingService::class)->processBinary(
        contents: test_image_binary('jpeg', 2400, 1200),
        originalName: 'large.jpg',
        contentType: 'image/jpeg',
        profile: 'product_gallery',
        directory: 'uploads/products/1',
    );

    expect($processed->width)->toBe(1600)
        ->and($processed->height)->toBe(800)
        ->and($processed->size)->toBeGreaterThan(0)
        ->and($processed->mime)->toBe('image/webp');
});

test('product can have multiple images but only one main image', function () {
    $product = Product::factory()->create();

    $first = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'legacy/first.webp',
    ]);

    $second = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'legacy/second.webp',
    ]);

    expect($first->fresh()->is_main)->toBeFalse()
        ->and($second->fresh()->is_main)->toBeTrue()
        ->and($product->images()->count())->toBe(2);
});

test('manual product image from public disk is processed through media service', function () {
    $product = Product::factory()->create();
    Storage::disk('public')->put('uploads/products/manual/raw.jpg', test_image_binary('jpeg', 120, 80));

    $image = ProductImage::query()->create([
        'product_id' => $product->getKey(),
        'path' => 'uploads/products/manual/raw.jpg',
        'alt' => 'Manual image',
        'is_main' => true,
    ])->refresh();

    Storage::disk('public')->assertExists($image->path);

    expect($image->path)->toStartWith('uploads/products/'.$product->getKey().'/')
        ->and($image->path)->toEndWith('.webp')
        ->and($image->mime)->toBe('image/webp')
        ->and($image->width)->toBe(120)
        ->and($image->height)->toBe(80)
        ->and($image->checksum)->toHaveLength(64)
        ->and($image->conversions)->toHaveKeys(['thumb', 'card']);
});

test('manual vehicle make and generation images are processed through media service', function () {
    Storage::disk('public')->put('uploads/vehicles/makes/manual/make.png', test_image_binary('png', 700, 700));
    Storage::disk('public')->put('uploads/vehicles/generations/manual/generation.png', test_image_binary('png', 1600, 900));

    $make = VehicleMake::factory()->create(['image' => 'uploads/vehicles/makes/manual/make.png'])->refresh();
    $generation = VehicleGeneration::factory()->create(['image' => 'uploads/vehicles/generations/manual/generation.png'])->refresh();

    Storage::disk('public')->assertExists($make->image);
    Storage::disk('public')->assertExists($generation->image);

    expect($make->image)->toStartWith('uploads/vehicles/makes/'.$make->getKey().'/')
        ->and($make->image)->toEndWith('.webp')
        ->and($generation->image)->toStartWith('uploads/vehicles/generations/'.$generation->getKey().'/')
        ->and($generation->image)->toEndWith('.webp');
});

test('import product image creates processed product image record', function () {
    Http::fake([
        'https://example.test/product.jpg' => Http::response(test_image_binary('jpeg', 640, 480), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $product = Product::factory()->create();

    $image = app(ImportImageDownloader::class)->download($product, 'https://example.test/product.jpg');

    Storage::disk('public')->assertExists($image->path);

    expect($image->source_url)->toBe('https://example.test/product.jpg')
        ->and($image->mime)->toBe('image/webp')
        ->and($image->path)->toStartWith('uploads/products/'.$product->getKey().'/')
        ->and($image->conversions)->toHaveKeys(['thumb', 'card']);
});

test('import vehicle generation image updates generation image with processed webp', function () {
    Http::fake([
        'https://example.test/car.png' => Http::response(test_image_binary('png', 1600, 900), 200, ['Content-Type' => 'image/png']),
    ]);

    $generation = VehicleGeneration::factory()->create();

    $generation = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($generation, 'https://example.test/car.png');

    Storage::disk('public')->assertExists($generation->image);

    expect($generation->image)->toStartWith('uploads/vehicles/generations/'.$generation->getKey().'/')
        ->and($generation->image)->toEndWith('.webp')
        ->and($generation->image_source_url)->toBe('https://example.test/car.png');
});


test('repeated import of same product image does not leave orphan files', function () {
    Http::fake([
        'https://example.test/same.jpg' => Http::response(test_image_binary('jpeg', 640, 480), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $product = Product::factory()->create();
    $downloader = app(ImportImageDownloader::class);

    $first = $downloader->download($product, 'https://example.test/same.jpg');
    $filesAfterFirst = Storage::disk('public')->allFiles();
    $second = $downloader->download($product, 'https://example.test/same.jpg');
    $filesAfterSecond = Storage::disk('public')->allFiles();

    expect($second->getKey())->toBe($first->getKey())
        ->and(ProductImage::query()->where('product_id', $product->getKey())->count())->toBe(1)
        ->and($filesAfterSecond)->toEqualCanonicalizing($filesAfterFirst);
});

test('replacing vehicle generation image removes previous files and keeps same checksum without rewrite', function () {
    Http::fake([
        'https://example.test/first.jpg' => Http::response(test_image_binary('jpeg', 800, 500), 200, ['Content-Type' => 'image/jpeg']),
        'https://example.test/second.jpg' => Http::response(test_image_binary('png', 900, 500), 200, ['Content-Type' => 'image/png']),
    ]);

    $generation = VehicleGeneration::factory()->create();
    $downloader = app(ImportImageDownloader::class);

    $generation = $downloader->downloadVehicleGenerationImage($generation, 'https://example.test/first.jpg');
    $firstPath = $generation->image;
    Storage::disk('public')->assertExists($firstPath);

    $generation = $downloader->downloadVehicleGenerationImage($generation, 'https://example.test/second.jpg');

    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($generation->image);
});

test('product image deletion removes file and conversions', function () {
    $product = Product::factory()->create();

    $directory = 'uploads/products/'.$product->getKey();
    $mainPath = $directory.'/main.webp';
    $thumbPath = $directory.'/conversions/main_thumb.webp';

    $mainBinary = test_image_binary('webp', 80, 60);
    $thumbBinary = test_image_binary('webp', 30, 30);

    Storage::disk('public')->put($mainPath, $mainBinary);
    Storage::disk('public')->put($thumbPath, $thumbBinary);

    $image = ProductImage::factory()->forProduct($product)->create([
        'disk' => 'public',
        'path' => $mainPath,
        'mime' => 'image/webp',
        'width' => 80,
        'height' => 60,
        'size' => strlen($mainBinary),
        'checksum' => hash('sha256', $mainBinary),
        'conversions' => [
            'thumb' => [
                'disk' => 'public',
                'path' => $thumbPath,
                'mime' => 'image/webp',
                'width' => 30,
                'height' => 30,
                'size' => strlen($thumbBinary),
            ],
        ],
    ]);

    $image->delete();

    Storage::disk('public')->assertMissing($mainPath);
    Storage::disk('public')->assertMissing($thumbPath);
});


test('image job with missing product is counted as failed and can finish import', function () {
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::ProcessingImages,
        'queued_images' => 1,
    ]);

    (new DownloadProductImageJob(999999, 'https://example.test/missing.jpg', $run->getKey()))->handle(
        app(ImportImageDownloader::class),
        app(ImportLogger::class),
        app(ImportStatusService::class),
    );

    expect($run->fresh()->failed_images)->toBe(1)
        ->and($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and(ImportLog::query()->where('level', ImportLogLevel::Warning->value)->exists())->toBeTrue();
});

test('image job with missing vehicle generation is counted as failed and can finish import', function () {
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::ProcessingImages,
        'queued_images' => 1,
    ]);

    (new DownloadVehicleGenerationImageJob(999999, 'https://example.test/missing.jpg', $run->getKey()))->handle(
        app(ImportImageDownloader::class),
        app(ImportLogger::class),
        app(ImportStatusService::class),
    );

    expect($run->fresh()->failed_images)->toBe(1)
        ->and($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and(ImportLog::query()->where('level', ImportLogLevel::Warning->value)->exists())->toBeTrue();
});
