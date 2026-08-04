<?php

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Media\DefaultProductImageService;
use App\Services\Media\ProductGalleryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('public'));

test('gallery keeps at most one visible main image under repeated direct saves', function (): void {
    $product = Product::factory()->create();
    $first = ProductImage::factory()->forProduct($product)->main()->create();
    $second = ProductImage::factory()->forProduct($product)->main()->create();

    expect($first->refresh()->is_main)->toBeFalse()
        ->and($second->refresh()->is_main)->toBeTrue()
        ->and($second->is_visible)->toBeTrue()
        ->and($product->images()->where('is_main', true)->count())->toBe(1);
});

test('image variant must belong to the same product', function (): void {
    $product = Product::factory()->create();
    $foreignVariant = ProductVariant::factory()->create();

    expect(fn () => ProductImage::factory()->forProduct($product)->create([
        'product_variant_id' => $foreignVariant->getKey(),
    ]))->toThrow(ValidationException::class, 'тому же товару');

    expect(ProductImage::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('image rejects forged product and variant ids before creating rows or files', function (string $field, mixed $value): void {
    $product = Product::factory()->create();
    $image = ProductImage::factory()->make([
        'product_id' => $product->getKey(),
        'product_variant_id' => null,
        $field => $value,
    ]);

    expect(fn () => $image->save())
        ->toThrow(ValidationException::class, 'положительным целым числом');

    expect($image->exists)->toBeFalse()
        ->and(ProductImage::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'product mixed string' => ['product_id', '1abc'],
    'product zero' => ['product_id', 0],
    'variant mixed string' => ['product_variant_id', '1abc'],
]);

test('image rejects a missing product and keeps the gallery untouched', function (): void {
    $image = ProductImage::factory()->make(['product_id' => 999999]);

    expect(fn () => $image->save())
        ->toThrow(ValidationException::class, 'Товар изображения не существует');

    expect(ProductImage::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('existing image cannot move to another product and preserves both galleries and files', function (): void {
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create();
    $path = 'uploads/products/'.$product->getKey().'/immutable.webp';
    $thumb = 'uploads/products/'.$product->getKey().'/conversions/immutable-thumb.webp';
    $image = ProductImage::factory()->forVariant($variant)->main()->create([
        'path' => $path,
        'conversions' => ['thumb' => ['disk' => 'public', 'path' => $thumb]],
    ]);
    $otherMain = ProductImage::factory()->forProduct($otherProduct)->main()->create();
    Storage::disk('public')->put($path, 'image');
    Storage::disk('public')->put($thumb, 'thumb');
    $originalConversions = $image->conversions;

    expect(fn () => $image->forceFill(['product_id' => $otherProduct->getKey()])->save())
        ->toThrow(ValidationException::class, 'Нельзя переносить существующее изображение между товарами');

    expect($image->refresh()->product_id)->toBe($product->getKey())
        ->and($image->product_variant_id)->toBe($variant->getKey())
        ->and($image->path)->toBe($path)
        ->and($image->conversions)->toBe($originalConversions)
        ->and($image->is_main)->toBeTrue()
        ->and($otherMain->refresh()->is_main)->toBeTrue()
        ->and($product->images()->where('is_main', true)->value('id'))->toBe($image->getKey())
        ->and($otherProduct->images()->where('is_main', true)->value('id'))->toBe($otherMain->getKey())
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->exists($thumb))->toBeTrue();
});

test('deleting a main image promotes a deterministic visible fallback', function (): void {
    $product = Product::factory()->create();
    $main = ProductImage::factory()->forProduct($product)->main()->create(['position' => 1]);
    $fallback = ProductImage::factory()->forProduct($product)->create(['position' => 2, 'is_visible' => true]);

    app(ProductGalleryService::class)->deleteImage($main);

    expect($fallback->refresh()->is_main)->toBeTrue()
        ->and($product->images()->where('is_main', true)->count())->toBe(1);
});

test('direct image delete uses atomic fallback and after commit cleanup', function (): void {
    $product = Product::factory()->create();
    $mainPath = 'uploads/products/'.$product->getKey().'/direct-main.webp';
    $fallbackPath = 'uploads/products/'.$product->getKey().'/direct-fallback.webp';
    $main = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => $mainPath,
        'position' => 20,
    ]);
    $fallback = ProductImage::factory()->forProduct($product)->create([
        'path' => $fallbackPath,
        'position' => 10,
        'is_visible' => true,
    ]);
    Storage::disk('public')->put($mainPath, 'main');
    Storage::disk('public')->put($fallbackPath, 'fallback');

    $main->delete();

    expect($main->fresh())->toBeNull()
        ->and($fallback->refresh()->is_main)->toBeTrue()
        ->and($product->images()->where('is_main', true)->count())->toBe(1)
        ->and(Storage::disk('public')->exists($mainPath))->toBeFalse()
        ->and(Storage::disk('public')->exists($fallbackPath))->toBeTrue();
});

test('direct ordinary image delete preserves the current main image', function (): void {
    $product = Product::factory()->create();
    $main = ProductImage::factory()->forProduct($product)->main()->create();
    $path = 'uploads/products/'.$product->getKey().'/direct-ordinary.webp';
    $ordinary = ProductImage::factory()->forProduct($product)->create(['path' => $path]);
    Storage::disk('public')->put($path, 'ordinary');

    $ordinary->delete();

    expect($ordinary->fresh())->toBeNull()
        ->and($main->refresh()->is_main)->toBeTrue()
        ->and($product->images()->where('is_main', true)->count())->toBe(1)
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

test('direct image delete rollback preserves rows flags and physical files', function (): void {
    $product = Product::factory()->create();
    $mainPath = 'uploads/products/'.$product->getKey().'/direct-rollback-main.webp';
    $fallbackPath = 'uploads/products/'.$product->getKey().'/direct-rollback-fallback.webp';
    $main = ProductImage::factory()->forProduct($product)->main()->create(['path' => $mainPath]);
    $fallback = ProductImage::factory()->forProduct($product)->create([
        'path' => $fallbackPath,
        'is_visible' => true,
    ]);
    Storage::disk('public')->put($mainPath, 'main');
    Storage::disk('public')->put($fallbackPath, 'fallback');

    expect(fn () => DB::transaction(function () use ($main): void {
        $main->delete();

        throw new RuntimeException('rollback direct delete');
    }))->toThrow(RuntimeException::class, 'rollback direct delete');

    expect($main->refresh()->is_main)->toBeTrue()
        ->and($fallback->refresh()->is_main)->toBeFalse()
        ->and($product->images()->where('is_main', true)->value('id'))->toBe($main->getKey())
        ->and(Storage::disk('public')->exists($mainPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($fallbackPath))->toBeTrue();
});

test('direct delete removes a default row without deleting the shared default file', function (): void {
    $partType = PartType::factory()->create(['default_image_key' => 'porog']);
    $product = Product::factory()->forPartType($partType)->create();
    $defaultSource = app(DefaultProductImageService::class)->forPartType($partType);
    $image = ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => DefaultProductImageService::DISK,
        'path' => $defaultSource['path'],
        'source_type' => ProductImage::SOURCE_DEFAULT,
        'is_default' => true,
    ]);

    $image->delete();

    expect($image->fresh())->toBeNull()
        ->and(is_file($defaultSource['absolute_path']))->toBeTrue();
});

test('gallery reset rollback preserves rows main flags and physical files', function (): void {
    $partType = PartType::factory()->create(['default_image_key' => 'porog']);
    $product = Product::factory()->forPartType($partType)->create();
    $manualPath = 'uploads/products/'.$product->getKey().'/rollback-manual.webp';
    $importPath = 'uploads/products/'.$product->getKey().'/rollback-import.webp';
    $manual = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => $manualPath,
        'source_type' => ProductImage::SOURCE_MANUAL,
    ]);
    $import = ProductImage::factory()->forProduct($product)->create([
        'path' => $importPath,
        'source_type' => ProductImage::SOURCE_IMPORT,
    ]);
    Storage::disk('public')->put($manualPath, 'manual');
    Storage::disk('public')->put($importPath, 'import');
    $before = $product->images()->orderBy('id')->get(['id', 'is_main', 'is_visible'])->toArray();

    $gallery = new class(app(DefaultProductImageService::class)) extends ProductGalleryService
    {
        protected function persistResetDefault(
            Product $product,
            array $defaultSource,
            ?ProductImage $existingDefault,
        ): ProductImage {
            throw new RuntimeException('Смоделированная ошибка reset');
        }
    };

    expect(fn () => $gallery->resetToDefault($product))
        ->toThrow(RuntimeException::class, 'Смоделированная ошибка reset');

    expect($product->images()->orderBy('id')->get(['id', 'is_main', 'is_visible'])->toArray())->toBe($before)
        ->and($manual->refresh()->is_main)->toBeTrue()
        ->and($import->refresh()->is_main)->toBeFalse()
        ->and(Storage::disk('public')->exists($manualPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($importPath))->toBeTrue()
        ->and($product->defaultImages()->count())->toBe(0);
});

test('successful gallery reset commits one default main and deletes only mutable files', function (): void {
    $partType = PartType::factory()->create(['default_image_key' => 'porog']);
    $product = Product::factory()->forPartType($partType)->create();
    $manualPath = 'uploads/products/'.$product->getKey().'/success-manual.webp';
    $importPath = 'uploads/products/'.$product->getKey().'/success-import.webp';
    ProductImage::factory()->forProduct($product)->main()->create([
        'path' => $manualPath,
        'source_type' => ProductImage::SOURCE_MANUAL,
    ]);
    ProductImage::factory()->forProduct($product)->create([
        'path' => $importPath,
        'source_type' => ProductImage::SOURCE_IMPORT,
    ]);
    Storage::disk('public')->put($manualPath, 'manual');
    Storage::disk('public')->put($importPath, 'import');
    $defaultSource = app(DefaultProductImageService::class)->forPartType($partType);

    $default = app(ProductGalleryService::class)->resetToDefault($product);

    expect($product->images()->count())->toBe(1)
        ->and($product->images()->where('is_main', true)->count())->toBe(1)
        ->and($default->source_type)->toBe(ProductImage::SOURCE_DEFAULT)
        ->and($default->is_default)->toBeTrue()
        ->and($default->is_main)->toBeTrue()
        ->and($default->is_visible)->toBeTrue()
        ->and(Storage::disk('public')->exists($manualPath))->toBeFalse()
        ->and(Storage::disk('public')->exists($importPath))->toBeFalse()
        ->and(is_file($defaultSource['absolute_path']))->toBeTrue();
});

test('manual image replacement rollback preserves old files and removes partial new files', function (): void {
    $product = Product::factory()->create();
    $oldPath = 'uploads/products/'.$product->getKey().'/old.webp';
    $oldThumb = 'uploads/products/'.$product->getKey().'/conversions/old-thumb.webp';
    $sourcePath = 'uploads/products/pending/replacement.png';
    Storage::disk('public')->put($oldPath, test_image_binary('webp'));
    Storage::disk('public')->put($oldThumb, test_image_binary('webp', 40, 30));
    Storage::disk('public')->put($sourcePath, test_image_binary('png'));
    $image = ProductImage::factory()->forProduct($product)->create([
        'path' => $oldPath,
        'mime' => 'image/webp',
        'checksum' => str_repeat('a', 64),
        'conversions' => ['thumb' => ['disk' => 'public', 'path' => $oldThumb]],
    ]);
    expect(fn () => DB::transaction(function () use ($image, $sourcePath): void {
        $image->forceFill(['path' => $sourcePath, 'mime' => null, 'checksum' => null])->save();

        throw new RuntimeException('Ошибка следующего этапа сохранения товара');
    }))->toThrow(RuntimeException::class, 'Ошибка следующего этапа');

    expect($image->refresh()->path)->toBe($oldPath)
        ->and(Storage::disk('public')->exists($oldPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($oldThumb))->toBeTrue()
        ->and(Storage::disk('public')->exists($sourcePath))->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toEqualCanonicalizing([$oldPath, $oldThumb]);
});

test('manual image replacement deletes old files only after successful commit', function (): void {
    $product = Product::factory()->create();
    $oldPath = 'uploads/products/'.$product->getKey().'/commit-old.webp';
    $oldThumb = 'uploads/products/'.$product->getKey().'/conversions/commit-old-thumb.webp';
    $sourcePath = 'uploads/products/pending/commit-replacement.png';
    Storage::disk('public')->put($oldPath, test_image_binary('webp'));
    Storage::disk('public')->put($oldThumb, test_image_binary('webp', 40, 30));
    Storage::disk('public')->put($sourcePath, test_image_binary('png'));
    $image = ProductImage::factory()->forProduct($product)->create([
        'path' => $oldPath,
        'mime' => 'image/webp',
        'checksum' => str_repeat('b', 64),
        'conversions' => ['thumb' => ['disk' => 'public', 'path' => $oldThumb]],
    ]);

    DB::transaction(function () use ($image, $sourcePath, $oldPath, $oldThumb): void {
        $image->forceFill(['path' => $sourcePath, 'mime' => null, 'checksum' => null])->save();

        expect(Storage::disk('public')->exists($oldPath))->toBeTrue()
            ->and(Storage::disk('public')->exists($oldThumb))->toBeTrue();
    });

    $newPath = $image->refresh()->path;

    expect($newPath)->not->toBe($oldPath)
        ->and(Storage::disk('public')->exists($newPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('public')->exists($oldThumb))->toBeFalse()
        ->and(Storage::disk('public')->exists($sourcePath))->toBeFalse();
});
