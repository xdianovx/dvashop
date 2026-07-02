<?php

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Import\ImportImageDownloader;
use App\Services\Import\ImportProductFactory;
use App\Services\Import\ImportRowProcessor;
use App\Services\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function catalogImportRun(array $state = []): ImportRun
{
    return ImportRun::factory()->create(array_merge([
        'status' => ImportRunStatus::Running,
        'total_rows' => 1,
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => 'Кузовные детали',
                'title' => 'Пороги',
                'category_title' => 'Пороги',
            ],
        ],
    ], $state));
}

function catalogRow(array $overrides = []): array
{
    return array_replace([
        0 => '',
        1 => 'Toyota',
        2 => 'Camry',
        3 => 'XV70',
        4 => '2017-2023',
        5 => 'седан',
        6 => '1',
    ], $overrides);
}

function processCatalogRow(ImportRun $run, array $row): void
{
    Queue::fake();

    app(ImportRowProcessor::class)->process(
        run: $run,
        row: $row,
        detailColumns: $run->detail_columns,
        rowNumber: 3,
    );
}

test('merged detail headers are read correctly', function () {
    $path = storage_path('framework/testing/catalog-merged-headers.csv');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, implode("\n", [
        ',,,,,,Кузовные детали,,Оптика',
        'Фото,Марка,Модель,Поколение,Годы,Кузов,Пороги,Арки,Фары',
        ',Toyota,Camry,XV70,2017-2023,седан,1,1,',
    ]));

    $headers = app(SpreadsheetReader::class)->readMergedDetailHeaders($path);

    expect($headers[6])->toBe([
        'index' => 6,
        'group' => 'Кузовные детали',
        'title' => 'Пороги',
        'category_title' => 'Пороги',
    ])->and($headers[7]['group'])->toBe('Кузовные детали')
        ->and($headers[7]['category_title'])->toBe('Арки')
        ->and($headers[8]['group'])->toBe('Оптика')
        ->and($headers[8]['category_title'])->toBe('Фары')
        ->and(app(SpreadsheetReader::class)->countRows($path, 2))->toBe(1);
});

test('row creates vehicle make model and generation', function () {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow());

    $make = VehicleMake::query()->firstOrFail();
    $model = VehicleModel::query()->firstOrFail();
    $generation = VehicleGeneration::query()->firstOrFail();

    expect($make->title)->toBe('Toyota')
        ->and($model->title)->toBe('Camry')
        ->and($model->vehicle_make_id)->toBe($make->getKey())
        ->and($generation->title)->toBe('XV70')
        ->and($generation->years_label)->toBe('2017-2023')
        ->and($generation->body)->toBe('седан')
        ->and($generation->vehicle_model_id)->toBe($model->getKey());
});

test('row creates product category tree from detail columns', function () {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow());

    $parent = ProductCategory::query()->whereNull('parent_id')->firstOrFail();
    $child = ProductCategory::query()->whereNotNull('parent_id')->firstOrFail();

    expect($parent->title)->toBe('Кузовные детали')
        ->and($parent->full_slug)->toBe($parent->slug)
        ->and($child->title)->toBe('Пороги')
        ->and($child->parent_id)->toBe($parent->getKey())
        ->and($child->full_slug)->toBe($parent->slug.'/'.$child->slug);
});

test('row creates product default variant and fitment', function () {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow());

    $product = Product::query()->firstOrFail();
    $variant = ProductVariant::query()->firstOrFail();
    $fitment = ProductFitment::query()->firstOrFail();
    $generation = VehicleGeneration::query()->firstOrFail();

    expect($product->title)->toContain('Пороги')
        ->and($product->title)->toContain('Toyota Camry XV70')
        ->and($product->status)->toBe(ProductStatus::Active)
        ->and($product->import_key)->not->toBeNull()
        ->and($product->last_import_run_id)->toBe((string) $run->getKey())
        ->and($variant->product_id)->toBe($product->getKey())
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->price)->toBe('0.00')
        ->and($fitment->product_id)->toBe($product->getKey())
        ->and($fitment->vehicle_generation_id)->toBe($generation->getKey());
});

test('repeated import updates existing product without duplicates', function () {
    $firstRun = catalogImportRun();
    $secondRun = catalogImportRun();

    processCatalogRow($firstRun, catalogRow());
    processCatalogRow($secondRun, catalogRow());

    expect(VehicleMake::query()->count())->toBe(1)
        ->and(VehicleModel::query()->count())->toBe(1)
        ->and(VehicleGeneration::query()->count())->toBe(1)
        ->and(ProductCategory::query()->count())->toBe(2)
        ->and(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductFitment::query()->count())->toBe(1)
        ->and(Product::query()->firstOrFail()->last_import_run_id)->toBe((string) $secondRun->getKey());
});

test('missing imported products are archived only after successful running import', function () {
    $oldProduct = Product::factory()->create([
        'import_key' => 'catalog:old:product',
        'last_import_run_id' => '1',
        'status' => ProductStatus::Active,
    ]);

    $failedRun = catalogImportRun([
        'status' => ImportRunStatus::Failed,
        'total_rows' => 1,
    ]);

    expect(app(ImportProductFactory::class)->archiveMissingProducts($failedRun))->toBe(0)
        ->and($oldProduct->fresh()->status)->toBe(ProductStatus::Active);

    $successfulRun = catalogImportRun([
        'status' => ImportRunStatus::Running,
        'total_rows' => 1,
    ]);

    expect(app(ImportProductFactory::class)->archiveMissingProducts($successfulRun))->toBe(1)
        ->and($oldProduct->fresh()->status)->toBe(ProductStatus::Archived);
});

test('catalog chunk archives missing products after successful import', function () {
    Storage::fake('local');
    Queue::fake();

    $oldProduct = Product::factory()->create([
        'import_key' => 'catalog:old:product',
        'last_import_run_id' => '1',
        'status' => ProductStatus::Active,
    ]);

    Storage::disk('local')->put('imports/catalog/catalog.csv', implode("\n", [
        ',,,,,,Кузовные детали',
        'Фото,Марка,Модель,Поколение,Годы,Кузов,Пороги',
        ',Toyota,Camry,XV70,2017-2023,седан,1',
    ]));

    $run = catalogImportRun([
        'stored_path' => 'imports/catalog/catalog.csv',
        'total_rows' => 1,
        'processed_rows' => 0,
        'current_row' => 0,
        'chunk_size' => 10,
        'detail_columns' => app(SpreadsheetReader::class)->readMergedDetailHeaders(
            Storage::disk('local')->path('imports/catalog/catalog.csv')
        ),
    ]);

    (new CatalogImportChunkJob($run->getKey()))->handle(
        app(SpreadsheetReader::class),
        app(\App\Services\ImportStatusService::class),
        app(\App\Services\ImportLogger::class),
        app(ImportRowProcessor::class),
        app(ImportProductFactory::class),
    );

    expect($run->fresh()->status)->toBe(ImportRunStatus::Done)
        ->and($oldProduct->fresh()->status)->toBe(ProductStatus::Archived);
});

test('fake http image download saves product image file', function () {
    Storage::fake('public');
    Http::fake([
        'https://example.test/image.jpg' => Http::response('fake-image-content', 200),
    ]);

    $run = catalogImportRun();
    processCatalogRow($run, catalogRow());

    $product = Product::query()->firstOrFail();
    $image = app(ImportImageDownloader::class)->download($product, 'https://example.test/image.jpg');

    Storage::disk('public')->assertExists($image->path);

    expect(ProductImage::query()->count())->toBe(1)
        ->and($image->path)->toEndWith('/image.webp')
        ->and($image->alt)->toBe($product->title);
});
