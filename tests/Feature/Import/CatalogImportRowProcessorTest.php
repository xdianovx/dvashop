<?php

use App\Enums\ImportLogLevel;
use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportLog;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function catalogImportRun(array $state = []): ImportRun
{
    return ImportRun::factory()->create(array_merge([
        'type' => 'catalog',
        'status' => ImportRunStatus::RunningRows,
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

function catalogTestColumnName(int $zeroBasedIndex): string
{
    $number = $zeroBasedIndex + 1;
    $name = '';

    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $name = chr(65 + $remainder).$name;
        $number = intdiv($number - 1, 26);
    }

    return $name;
}

function catalogTestCellXml(int $columnIndex, int $rowIndex, mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    $reference = catalogTestColumnName($columnIndex).$rowIndex;
    $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return '<c r="'.$reference.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
}

/**
 * @param array<int, array<int, mixed>> $rows
 * @param array<int, string> $mergedRanges
 */
function writeCatalogTestXlsx(string $path, array $rows, array $mergedRanges = []): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    $sheetRows = '';
    $maxColumn = 0;
    $maxRow = 0;

    foreach ($rows as $rowIndex => $cells) {
        $rowXml = '';
        $maxRow = max($maxRow, $rowIndex);

        foreach ($cells as $columnIndex => $value) {
            $maxColumn = max($maxColumn, (int) $columnIndex);
            $rowXml .= catalogTestCellXml((int) $columnIndex, (int) $rowIndex, $value);
        }

        $sheetRows .= '<row r="'.$rowIndex.'">'.$rowXml.'</row>';
    }

    $mergeXml = '';
    if ($mergedRanges !== []) {
        $mergeXml = '<mergeCells count="'.count($mergedRanges).'">'.implode('', array_map(
            static fn (string $range): string => '<mergeCell ref="'.$range.'"/>',
            $mergedRanges,
        )).'</mergeCells>';
    }

    $dimension = 'A1:'.catalogTestColumnName($maxColumn).max(1, $maxRow);
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<dimension ref="'.$dimension.'"/><sheetData>'.$sheetRows.'</sheetData>'.$mergeXml.'</worksheet>';

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Каталог" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
}

test('csv merged detail headers keep legacy fallback behaviour', function () {
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

test('xlsx merged detail headers use real merge ranges and reset group after range ends', function () {
    $path = storage_path('framework/testing/catalog-real-merged.xlsx');

    writeCatalogTestXlsx($path, [
        1 => [
            6 => 'Порог',
            7 => 'Арка',
            12 => 'Пенка',
        ],
        2 => [
            6 => '',
            7 => 'Задняя',
            8 => 'Передняя',
            9 => 'Внутренняя',
            10 => 'Внутренняя универсальная',
            11 => 'Карман задняя',
            12 => 'Задней двери',
            13 => 'Передней двери',
            14 => 'Багажника',
            15 => 'Лонжерон',
            16 => 'Торцевая заглушка',
            17 => 'Ремкомплект пола',
            18 => 'Усилитель / соединитель порогов',
        ],
        3 => [
            0 => 'https://example.test/car.jpg',
            1 => 'Toyota',
            2 => 'Camry',
            3 => 'XV70',
            4 => '2017-2023',
            5 => 'седан',
            6 => '1',
            7 => '1',
            8 => '1',
            12 => '1',
            15 => '1',
            16 => '1',
            17 => '1',
            18 => '1',
        ],
    ], ['H1:L1', 'M1:O1']);

    $headers = app(SpreadsheetReader::class)->readMergedDetailHeaders($path);

    expect($headers[6]['group'])->toBeNull()
        ->and($headers[6]['category_title'])->toBe('Порог')
        ->and($headers[7]['group'])->toBe('Арка')
        ->and($headers[11]['group'])->toBe('Арка')
        ->and($headers[12]['group'])->toBe('Пенка')
        ->and($headers[14]['group'])->toBe('Пенка')
        ->and($headers[15]['group'])->toBeNull()
        ->and($headers[15]['category_title'])->toBe('Лонжерон')
        ->and($headers[16]['group'])->toBeNull()
        ->and($headers[17]['group'])->toBeNull()
        ->and($headers[18]['group'])->toBeNull()
        ->and(app(SpreadsheetReader::class)->countRows($path, 2))->toBe(1);

    $run = catalogImportRun(['detail_columns' => $headers]);
    Queue::fake();

    app(ImportRowProcessor::class)->process(
        run: $run,
        row: app(SpreadsheetReader::class)->readChunk($path, 0, 1, 2)[0],
        detailColumns: $headers,
        rowNumber: 3,
    );

    $penka = ProductCategory::query()->where('title', 'Пенка')->firstOrFail();

    expect(ProductCategory::query()->where('title', 'Задней двери')->firstOrFail()->parent_id)->toBe($penka->getKey())
        ->and(ProductCategory::query()->where('title', 'Лонжерон')->firstOrFail()->parent_id)->toBeNull()
        ->and(ProductCategory::query()->where('title', 'Торцевая заглушка')->firstOrFail()->parent_id)->toBeNull()
        ->and(ProductCategory::query()->where('title', 'Ремкомплект пола')->firstOrFail()->parent_id)->toBeNull()
        ->and(ProductCategory::query()->where('title', 'Усилитель / соединитель порогов')->firstOrFail()->parent_id)->toBeNull();
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

test('vehicle photo from column a is stored on generation and queued for download', function () {
    $run = catalogImportRun();
    $url = 'https://example.test/camry.jpg';

    processCatalogRow($run, catalogRow([0 => $url]));

    $generation = VehicleGeneration::query()->firstOrFail();

    expect($generation->image_source_url)->toBe($url)
        ->and(ProductImage::query()->count())->toBe(0);

    Queue::assertPushed(DownloadVehicleGenerationImageJob::class, fn (DownloadVehicleGenerationImageJob $job): bool => $job->vehicleGenerationId === $generation->getKey()
        && $job->url === $url
        && $job->importRunId === $run->getKey());
});

test('different bodies create different vehicle generations', function () {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow([5 => 'седан']));
    processCatalogRow($run, catalogRow([5 => 'универсал']));

    expect(VehicleGeneration::query()->count())->toBe(2)
        ->and(VehicleGeneration::query()->pluck('body')->sort()->values()->all())->toBe(['седан', 'универсал']);
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

    processCatalogRow($run, catalogRow(['6' => '1.0']));

    $product = Product::query()->firstOrFail();
    $variant = ProductVariant::query()->firstOrFail();
    $fitment = ProductFitment::query()->firstOrFail();
    $generation = VehicleGeneration::query()->firstOrFail();

    expect($product->title)->toContain('Пороги')
        ->and($product->title)->toContain('Toyota Camry XV70')
        ->and($product->status)->toBe(ProductStatus::Active)
        ->and($product->import_key)->not->toBeNull()
        ->and($product->import_source)->toBe('catalog')
        ->and($product->last_import_run_id)->toBe((string) $run->getKey())
        ->and($product->price)->toBeNull()
        ->and($product->sku)->toBeNull()
        ->and($variant->product_id)->toBe($product->getKey())
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->price)->toBe('0.00')
        ->and($fitment->product_id)->toBe($product->getKey())
        ->and($fitment->vehicle_generation_id)->toBe($generation->getKey());
});

test('non standard product cell value creates product and writes warning', function () {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow([6 => 'maver']));

    $product = Product::query()->firstOrFail();
    $log = ImportLog::query()->where('level', ImportLogLevel::Warning->value)->latest('id')->firstOrFail();

    expect($product->status)->toBe(ProductStatus::Active)
        ->and($log->message)->toContain('Нестандартное значение')
        ->and($log->context['row'])->toBe(3)
        ->and($log->context['column'])->toBe('G')
        ->and($log->context['value'])->toBe('maver')
        ->and($log->context['category'])->toContain('porogi');
});

test('negative product cell values do not create products', function (string $value) {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow([6 => $value]));

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(ProductFitment::query()->count())->toBe(0);
})->with(['', '0', '0.0', 'нет', 'no', 'false', '-']);

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

test('missing imported products are archived only inside current import source after successful running import', function () {
    $oldCatalogProduct = Product::factory()->create([
        'import_key' => 'catalog:old:product',
        'import_source' => 'catalog',
        'last_import_run_id' => '1',
        'status' => ProductStatus::Active,
    ]);

    $oldOtherSourceProduct = Product::factory()->create([
        'import_key' => 'other:old:product',
        'import_source' => 'other',
        'last_import_run_id' => '1',
        'status' => ProductStatus::Active,
    ]);

    $failedRun = catalogImportRun([
        'status' => ImportRunStatus::Failed,
        'total_rows' => 1,
    ]);

    expect(app(ImportProductFactory::class)->archiveMissingProducts($failedRun))->toBe(0)
        ->and($oldCatalogProduct->fresh()->status)->toBe(ProductStatus::Active);

    $successfulRun = catalogImportRun([
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 1,
    ]);

    expect(app(ImportProductFactory::class)->archiveMissingProducts($successfulRun))->toBe(1)
        ->and($oldCatalogProduct->fresh()->status)->toBe(ProductStatus::Archived)
        ->and($oldOtherSourceProduct->fresh()->status)->toBe(ProductStatus::Active);
});

test('catalog chunk archives missing products after successful import', function () {
    Storage::fake('local');
    Queue::fake();

    $oldProduct = Product::factory()->create([
        'import_key' => 'catalog:old:product',
        'import_source' => 'catalog',
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
        'https://example.test/image.jpg' => Http::response(test_image_binary('jpeg', 320, 240), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $run = catalogImportRun();
    processCatalogRow($run, catalogRow());

    $product = Product::query()->firstOrFail();
    $image = app(ImportImageDownloader::class)->download($product, 'https://example.test/image.jpg');

    Storage::disk('public')->assertExists($image->path);

    expect(ProductImage::query()->count())->toBe(1)
        ->and($image->path)->toStartWith('uploads/products/'.$product->getKey().'/')
        ->and($image->path)->toEndWith('.webp')
        ->and($image->mime)->toBe('image/webp')
        ->and($image->alt)->toBe($product->title);
});

test('fake http vehicle generation image download saves image path on generation', function () {
    Storage::fake('public');
    Http::fake([
        'https://example.test/car.jpg' => Http::response(test_image_binary('jpeg', 640, 360), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $run = catalogImportRun();
    processCatalogRow($run, catalogRow([0 => 'https://example.test/car.jpg']));

    $generation = VehicleGeneration::query()->firstOrFail();
    $generation = app(ImportImageDownloader::class)->downloadVehicleGenerationImage($generation, 'https://example.test/car.jpg');

    Storage::disk('public')->assertExists($generation->image);

    expect($generation->image)->toStartWith('uploads/vehicles/generations/'.$generation->getKey().'/')
        ->and($generation->image)->toEndWith('.webp');
});


test('import counters are not inflated by repeated unchanged rows', function () {
    $run = catalogImportRun();

    app(ImportRowProcessor::class)->prepareDetailColumns($run, $run->detail_columns);
    processCatalogRow($run, catalogRow());
    processCatalogRow($run, catalogRow());

    $run->refresh();

    expect($run->created_makes)->toBe(1)
        ->and($run->updated_makes)->toBe(0)
        ->and($run->created_models)->toBe(1)
        ->and($run->updated_models)->toBe(0)
        ->and($run->created_generations)->toBe(1)
        ->and($run->updated_generations)->toBe(0)
        ->and($run->created_products)->toBe(1)
        ->and($run->updated_products)->toBe(0);
});

test('long excel values produce bounded stable slugs and import keys', function () {
    $long = str_repeat('Очень длинное значение из Excel ', 20);
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow([
        1 => $long,
        2 => $long,
        3 => $long,
        4 => $long,
        5 => $long,
    ]));

    $product = Product::query()->firstOrFail();
    $make = VehicleMake::query()->firstOrFail();
    $model = VehicleModel::query()->firstOrFail();
    $generation = VehicleGeneration::query()->firstOrFail();

    expect(strlen($make->slug))->toBeLessThanOrEqual(100)
        ->and(strlen($model->slug))->toBeLessThanOrEqual(100)
        ->and(strlen($generation->slug))->toBeLessThanOrEqual(100)
        ->and(strlen($product->slug))->toBeLessThanOrEqual(150)
        ->and(strlen($product->import_key))->toBeLessThanOrEqual(240);
});

test('excel text normalization removes line breaks and duplicate spaces from categories and product titles', function () {
    $path = storage_path('framework/testing/catalog-normalized-headers.xlsx');

    writeCatalogTestXlsx($path, [
        1 => [12 => 'Пенка'],
        2 => [12 => "Задней\nдвери", 13 => "Передней\t  двери", 15 => 'Усилитель / соединитель   порогов'],
        3 => [1 => 'Toyota', 2 => 'Camry', 3 => 'XV70', 4 => '2017-2023', 5 => 'седан', 12 => '1', 13 => '1', 15 => '1'],
    ], ['M1:O1']);

    $headers = app(SpreadsheetReader::class)->readMergedDetailHeaders($path);
    $run = catalogImportRun(['detail_columns' => $headers]);

    app(ImportRowProcessor::class)->process(
        run: $run,
        row: app(SpreadsheetReader::class)->readChunk($path, 0, 1, 2)[0],
        detailColumns: $headers,
        rowNumber: 3,
    );

    expect($headers[12]['category_title'])->toBe('Задней двери')
        ->and($headers[13]['category_title'])->toBe('Передней двери')
        ->and($headers[15]['category_title'])->toBe('Усилитель / соединитель порогов')
        ->and(ProductCategory::query()->where('title', "Задней\nдвери")->exists())->toBeFalse()
        ->and(ProductCategory::query()->where('title', 'Задней двери')->exists())->toBeTrue()
        ->and(Product::query()->where('title', 'like', "%\n%")->exists())->toBeFalse()
        ->and(Product::query()->where('title', 'like', "%  %")->exists())->toBeFalse();
});

test('chunk skips auto archive when row errors were logged', function () {
    Storage::fake('local');
    Queue::fake();

    $oldProduct = Product::factory()->create([
        'import_key' => 'catalog:old:product',
        'import_source' => 'catalog',
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
        'errors_count' => 1,
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

    expect($oldProduct->fresh()->status)->toBe(ProductStatus::Active)
        ->and($run->fresh()->archive_skipped)->toBeTrue()
        ->and(ImportLog::query()->where('message', 'like', '%Автоархивация пропущена%')->exists())->toBeTrue();
});

test('missing prepared category id skips only current product cell and writes warning', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => 'Порог', 'category_id' => 999999],
            7 => ['index' => 7, 'group' => null, 'title' => 'Арка', 'category_title' => 'Арка'],
        ],
    ]);

    app(ImportRowProcessor::class)->process($run, catalogRow([6 => '1', 7 => '1']), $run->detail_columns, 3);

    expect(Product::query()->count())->toBe(1)
        ->and(ImportLog::query()->where('message', 'like', '%detail_columns не найдена%')->exists())->toBeTrue();
});

test('product image source url is not queued again when existing file is present', function () {
    Storage::fake('public');
    Queue::fake();

    $run = catalogImportRun();
    processCatalogRow($run, catalogRow());
    $product = Product::query()->firstOrFail();
    $category = $product->category()->firstOrFail();

    Storage::disk('public')->put('uploads/products/'.$product->getKey().'/existing.webp', test_image_binary('webp', 20, 20));
    ProductImage::factory()->forProduct($product)->create([
        'disk' => 'public',
        'path' => 'uploads/products/'.$product->getKey().'/existing.webp',
        'source_url' => 'https://example.test/part.jpg',
        'checksum' => 'existing-checksum',
    ]);

    Queue::fake();

    app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: $run,
        generation: VehicleGeneration::query()->firstOrFail(),
        category: $category,
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => 'Порог'],
        cellValue: 'https://example.test/part.jpg',
        imageUrl: 'https://example.test/part.jpg',
    );

    Queue::assertNotPushed(\App\Jobs\DownloadProductImageJob::class);
});

test('product image source url is queued again when existing record file is missing', function () {
    Storage::fake('public');
    Queue::fake();

    $run = catalogImportRun();
    processCatalogRow($run, catalogRow());
    $product = Product::query()->firstOrFail();

    ProductImage::factory()->forProduct($product)->create([
        'disk' => 'public',
        'path' => 'uploads/products/'.$product->getKey().'/missing.webp',
        'source_url' => 'https://example.test/part.jpg',
        'checksum' => 'missing-checksum',
    ]);

    app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: $run,
        generation: VehicleGeneration::query()->firstOrFail(),
        category: ProductCategory::query()->firstOrFail(),
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => 'Порог'],
        cellValue: 'https://example.test/part.jpg',
        imageUrl: 'https://example.test/part.jpg',
    );

    Queue::assertPushed(\App\Jobs\DownloadProductImageJob::class);
});

test('vehicle image column availability and garbage values never create product images', function () {
    Queue::fake();

    $run = catalogImportRun();
    processCatalogRow($run, catalogRow([0 => '1.0']));

    Queue::assertNotPushed(DownloadVehicleGenerationImageJob::class);
    expect(ProductImage::query()->count())->toBe(0)
        ->and($run->fresh()->warnings_count)->toBe(0);

    app(ImportRowProcessor::class)->process($run, catalogRow([0 => 'not image']), $run->detail_columns, 4);

    expect(ImportLog::query()->where('message', 'like', '%колонке фото автомобиля%')->exists())->toBeTrue()
        ->and(ProductImage::query()->count())->toBe(0);
});

test('vehicle image url is not queued again when source file exists', function () {
    Storage::fake('public');
    Queue::fake();

    $run = catalogImportRun();
    app(ImportRowProcessor::class)->process($run, catalogRow([0 => 'https://example.test/car.jpg']), $run->detail_columns, 3);
    Queue::assertPushed(DownloadVehicleGenerationImageJob::class);

    $generation = VehicleGeneration::query()->firstOrFail();
    Storage::disk('public')->put('uploads/vehicles/generations/'.$generation->getKey().'/existing.webp', test_image_binary('webp', 20, 20));
    $generation->forceFill([
        'image' => 'uploads/vehicles/generations/'.$generation->getKey().'/existing.webp',
        'image_source_url' => 'https://example.test/car.jpg',
    ])->saveQuietly();

    Queue::fake();
    app(ImportRowProcessor::class)->process($run, catalogRow([0 => 'https://example.test/car.jpg']), $run->detail_columns, 4);

    Queue::assertNotPushed(DownloadVehicleGenerationImageJob::class);
});

test('import inspect command outputs category tree and does not write to database', function () {
    $path = storage_path('framework/testing/inspect-command.xlsx');

    writeCatalogTestXlsx($path, [
        1 => [7 => 'Арка', 12 => 'Пенка'],
        2 => [7 => 'Задняя', 12 => "Задней\nдвери", 15 => 'Лонжерон'],
        3 => [0 => '1.0', 1 => 'Toyota', 2 => 'Camry', 3 => 'XV70', 4 => '2017-2023', 5 => 'седан', 7 => '1', 12 => 'https://example.test/part.jpg', 15 => '1'],
    ], ['H1:L1', 'M1:O1']);

    Artisan::call('import:inspect-file', ['path' => $path]);
    $output = Artisan::output();

    expect($output)->toContain('Data rows: 1')
        ->and($output)->toContain('Пенка')
        ->and($output)->toContain('Задней двери')
        ->and($output)->toContain('Лонжерон')
        ->and($output)->toContain('Проверка P:S')
        ->and(ImportRun::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0);
});
