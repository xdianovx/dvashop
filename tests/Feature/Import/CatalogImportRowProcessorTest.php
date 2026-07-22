<?php

use App\Enums\ImportLogLevel;
use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\DownloadProductImageJob;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\PartType;
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
use App\Services\Media\DefaultProductImageService;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ProductCatalogSeeder::class);
});

function catalogImportRun(array $state = []): ImportRun
{
    return ImportRun::factory()->create(array_merge([
        'type' => 'catalog',
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 1,
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
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

    expect($headers[6])->toMatchArray([
        'index' => 6,
        'group' => 'Кузовные детали',
        'parent_title' => 'Кузовные детали',
        'title' => 'Пороги',
        'detail_title' => 'Пороги',
        'full_detail_title' => 'Кузовные детали пороги',
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
        ->and($headers[6]['full_detail_title'])->toBe('Порог')
        ->and($headers[7]['group'])->toBe('Арка')
        ->and($headers[7]['full_detail_title'])->toBe('Арка задняя')
        ->and($headers[10]['full_detail_title'])->toBe('Арка внутренняя универсальная')
        ->and($headers[12]['full_detail_title'])->toBe('Пенка задней двери')
        ->and($headers[15]['full_detail_title'])->toBe('Лонжерон')
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
    $prepared = app(ImportRowProcessor::class)->prepareDetailColumns($run, $headers);

    app(ImportRowProcessor::class)->process(
        run: $run,
        row: app(SpreadsheetReader::class)->readChunk($path, 0, 1, 2)[0],
        detailColumns: $prepared,
        rowNumber: 3,
    );

    expect($prepared[6]['part_type_full_slug'])->toBe('porog')
        ->and($prepared[7]['part_type_full_slug'])->toBe('arka/zadniaia')
        ->and($prepared[10]['part_type_full_slug'])->toBe('arka/vnutrenniaia-universalnaia')
        ->and($prepared[12]['part_type_full_slug'])->toBe('penka/zadnei-dveri')
        ->and($prepared[15]['part_type_full_slug'])->toBe('lonzheron')
        ->and($prepared[18]['part_type_full_slug'])->toBe('usilitel/soedinitel-porogov')
        ->and(ProductCategory::query()->whereIn('title', ['Порог', 'Арка', 'Пенка', 'Лонжерон', 'Торцевая заглушка', 'Ремкомплект пола'])->exists())->toBeFalse()
        ->and(ProductCategory::query()->count())->toBe(9);
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

test('row resolves part type and canonical store category without technical product categories', function () {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow());

    $product = Product::query()->with(['partType', 'category'])->firstOrFail();

    expect($product->product_type)->toBe(ProductType::AutoPart)
        ->and($product->partType->full_slug)->toBe('porog')
        ->and($product->category->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova/porogi')
        ->and(ProductCategory::query()->where('title', 'Порог')->exists())->toBeFalse()
        ->and(ProductCategory::query()->count())->toBe(9);
});

test('row creates product default variant and fitment', function () {
    $run = catalogImportRun();

    processCatalogRow($run, catalogRow(['6' => '1.0']));

    $product = Product::query()->firstOrFail();
    $variant = ProductVariant::query()->firstOrFail();
    $fitment = ProductFitment::query()->firstOrFail();
    $generation = VehicleGeneration::query()->firstOrFail();

    expect($product->title)->toContain('Порог для')
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
        ->and($log->context['part_type'])->toBe('porog')
        ->and($log->context['store_category'])->toContain('/porogi');
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
        ->and(ProductCategory::query()->count())->toBe(9)
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
        ',,,,,,',
        'Фото,Марка,Модель,Поколение,Годы,Кузов,Порог',
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
        ->and(PartType::query()->where('title', "Задней\nдвери")->exists())->toBeFalse()
        ->and(PartType::query()->where('full_slug', 'penka/zadnei-dveri')->exists())->toBeTrue()
        ->and(Product::query()->where('title', 'like', "%\n%")->exists())->toBeFalse()
        ->and(Product::query()->where('title', 'like', "%  %")->exists())->toBeFalse()
        ->and(Product::query()->where('title', 'like', 'Пенка задней двери для%')->exists())->toBeTrue()
        ->and(Product::query()->where('title', 'like', 'Пенка передней двери для%')->exists())->toBeTrue()
        ->and(Product::query()->where('title', 'like', 'Усилитель соединитель порогов для%')->exists())->toBeTrue();
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
        ',,,,,,',
        'Фото,Марка,Модель,Поколение,Годы,Кузов,Порог',
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

test('legacy detail columns ignore technical category id and resolve part types from text', function () {
    $legacy = ProductCategory::factory()->create([
        'title' => 'Порог',
        'slug' => 'porog',
        'full_slug' => 'porog',
        'is_active' => false,
    ]);
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
                'category_id' => $legacy->getKey(),
                'category_full_slug' => 'porog',
                'category_full_path' => 'Порог',
            ],
        ],
    ]);

    $processor = app(ImportRowProcessor::class);
    $processor->process($run, catalogRow([6 => '1']), $run->detail_columns, 3);
    $processor->process($run, catalogRow([3 => 'XV71', 6 => '1']), $run->detail_columns, 4);

    expect(Product::query()->count())->toBe(2)
        ->and(Product::query()->where('product_category_id', $legacy->getKey())->exists())->toBeFalse()
        ->and(Product::query()->whereHas('partType', fn ($query) => $query->where('full_slug', 'porog'))->count())->toBe(2)
        ->and($legacy->fresh()->is_active)->toBeFalse()
        ->and(ImportLog::query()->where('message', 'Устаревший формат detail_columns разрешён через PartType; category_id проигнорирован')->count())->toBe(1);
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
        partType: $product->partType()->firstOrFail(),
        storeCategory: $category,
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
        partType: $product->partType()->firstOrFail(),
        storeCategory: $product->category()->firstOrFail(),
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => 'Порог'],
        cellValue: 'https://example.test/part.jpg',
        imageUrl: 'https://example.test/part.jpg',
    );

    Queue::assertPushed(\App\Jobs\DownloadProductImageJob::class);
});

test('vehicle image column availability and garbage values never create product images', function () {
    Queue::fake();

    $run = catalogImportRun();
    processCatalogRow($run, catalogRow([0 => '1.0', 6 => '']));

    Queue::assertNotPushed(DownloadVehicleGenerationImageJob::class);
    expect(ProductImage::query()->count())->toBe(0)
        ->and($run->fresh()->warnings_count)->toBe(0);

    app(ImportRowProcessor::class)->process($run, catalogRow([0 => 'not image', 6 => '']), $run->detail_columns, 4);

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


test('grouped detail title is used in product title while category remains nested', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            10 => [
                'index' => 10,
                'group' => 'Арка',
                'parent_title' => 'Арка',
                'title' => 'Внутренняя универсальная',
                'detail_title' => 'Внутренняя универсальная',
                'full_detail_title' => 'Арка внутренняя универсальная',
                'category_title' => 'Внутренняя универсальная',
            ],
        ],
    ]);

    processCatalogRow($run, catalogRow([6 => '', 10 => '1', 1 => 'Acura', 2 => 'TSX', 3 => '2', 4 => '2008 - 2014', 5 => 'Седан 4 дв.']));

    $product = Product::query()->with(['partType', 'category'])->firstOrFail();

    expect($product->title)->toBe('Арка внутренняя универсальная для Acura TSX 2 2008 - 2014 Седан 4 дв.')
        ->and($product->partType->full_title)->toBe('Арка / Внутренняя универсальная')
        ->and($product->category->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova/arki')
        ->and(ProductCategory::query()->where('title', 'Арка')->exists())->toBeFalse()
        ->and($product->import_key)->toContain(':arka:')
        ->and($product->import_key)->toContain('vnutrenniaia-universalnaia')
        ->and($product->slug)->toStartWith('arka-vnutrenniaia-universalnaia-dlia-acura-tsx-2');
});

test('penka root and root-only detail titles are reflected in product titles', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            12 => [
                'index' => 12,
                'group' => 'Пенка',
                'parent_title' => 'Пенка',
                'title' => "Задней
двери",
                'detail_title' => 'Задней двери',
                'full_detail_title' => 'Пенка задней двери',
                'category_title' => 'Задней двери',
            ],
            15 => [
                'index' => 15,
                'group' => null,
                'parent_title' => null,
                'title' => 'Лонжерон',
                'detail_title' => 'Лонжерон',
                'full_detail_title' => 'Лонжерон',
                'category_title' => 'Лонжерон',
            ],
        ],
    ]);

    processCatalogRow($run, catalogRow([6 => '', 12 => '1', 15 => '1']));

    $titles = Product::query()->pluck('title')->sort()->values()->all();

    expect($titles)->toContain('Лонжерон для Toyota Camry XV70 2017-2023 седан')
        ->and($titles)->toContain('Пенка задней двери для Toyota Camry XV70 2017-2023 седан')
        ->and(PartType::query()->where('full_slug', 'penka/zadnei-dveri')->exists())->toBeTrue()
        ->and(PartType::query()->where('full_slug', 'lonzheron')->exists())->toBeTrue()
        ->and(ProductCategory::query()->whereIn('title', ['Пенка', 'Задней двери', 'Лонжерон'])->exists())->toBeFalse();
});

test('repeated import with full detail title updates same product without duplicates', function () {
    $detailColumns = [
        10 => [
            'index' => 10,
            'group' => 'Арка',
            'parent_title' => 'Арка',
            'title' => 'Внутренняя универсальная',
            'detail_title' => 'Внутренняя универсальная',
            'full_detail_title' => 'Арка внутренняя универсальная',
            'category_title' => 'Внутренняя универсальная',
        ],
    ];

    $firstRun = catalogImportRun(['detail_columns' => $detailColumns]);
    $secondRun = catalogImportRun(['detail_columns' => $detailColumns]);

    processCatalogRow($firstRun, catalogRow([6 => '', 10 => '1']));
    processCatalogRow($secondRun, catalogRow([6 => '', 10 => '1']));

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->firstOrFail()->last_import_run_id)->toBe((string) $secondRun->getKey());
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

test('positive availability cell attaches default image for root detail', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
            ],
        ],
    ]);

    processCatalogRow($run, catalogRow([6 => '1.0']));

    $product = Product::query()->firstOrFail();
    $image = ProductImage::query()->firstOrFail();

    expect($image->product_id)->toBe($product->getKey())
        ->and($image->source_type)->toBe('default')
        ->and($image->is_default)->toBeTrue()
        ->and($image->is_visible)->toBeTrue()
        ->and($image->is_main)->toBeTrue()
        ->and($image->disk)->toBe(DefaultProductImageService::DISK)
        ->and($image->path)->toStartWith('img/products_default/porog.')
        ->and($product->fresh()->main_image_url)->toContain('img/products_default/porog.');
});

test('grouped imported details attach mapped default images', function (string $parentTitle, string $detailTitle, string $expectedKey) {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => $parentTitle,
                'parent_title' => $parentTitle,
                'title' => $detailTitle,
                'detail_title' => $detailTitle,
                'full_detail_title' => $parentTitle.' '.mb_strtolower(mb_substr($detailTitle, 0, 1)).mb_substr($detailTitle, 1),
                'category_title' => $detailTitle,
            ],
        ],
    ]);

    processCatalogRow($run, catalogRow([6 => 'да']));

    $image = ProductImage::query()->firstOrFail();

    expect($image->source_type)->toBe('default')
        ->and($image->is_default)->toBeTrue()
        ->and($image->path)->toStartWith('img/products_default/'.$expectedKey.'.');
})->with([
    'arka vnutrenniaia universalnaia' => ['Арка', 'Внутренняя универсальная', 'arka-vnutrenniaia-universalnaia'],
    'penka zadnei dveri' => ['Пенка', 'Задней двери', 'penka-zadnei-dveri'],
]);

test('product cell URL queues import image and does not attach default image', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
            ],
        ],
    ]);
    $url = 'https://example.test/porog.jpg';

    processCatalogRow($run, catalogRow([6 => $url]));

    $product = Product::query()->firstOrFail();

    expect(ProductImage::query()->count())->toBe(0);
    Queue::assertPushed(DownloadProductImageJob::class, fn (DownloadProductImageJob $job): bool => $job->productId === $product->getKey()
        && $job->url === $url
        && $job->importRunId === $run->getKey());
});

test('repeated import does not duplicate default ProductImage', function () {
    $firstRun = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
            ],
        ],
    ]);
    $secondRun = catalogImportRun(['detail_columns' => $firstRun->detail_columns]);

    processCatalogRow($firstRun, catalogRow([6 => '1']));
    processCatalogRow($secondRun, catalogRow([6 => 'true']));

    expect(Product::query()->count())->toBe(1)
        ->and(ProductImage::query()->where('source_type', 'default')->count())->toBe(1);
});

test('manual main image is not reset by default image on repeated import', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
            ],
        ],
    ]);

    processCatalogRow($run, catalogRow([6 => '1']));

    $product = Product::query()->firstOrFail();
    $default = ProductImage::query()->where('source_type', 'default')->firstOrFail();
    $manual = ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => 'public',
        'path' => 'uploads/products/'.$product->getKey().'/manual.webp',
        'source_type' => 'manual',
        'is_default' => false,
    ]);

    processCatalogRow(catalogImportRun(['detail_columns' => $run->detail_columns]), catalogRow([6 => 'yes']));

    expect($manual->fresh()->is_main)->toBeTrue()
        ->and($default->fresh()->is_main)->toBeFalse()
        ->and(ProductImage::query()->where('source_type', 'default')->count())->toBe(1);
});

test('missing default image is logged once per detail type and import continues', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Неизвестная деталь',
                'detail_title' => 'Неизвестная деталь',
                'full_detail_title' => 'Неизвестная деталь',
                'category_title' => 'Неизвестная деталь',
            ],
        ],
    ]);

    Queue::fake();
    $processor = app(ImportRowProcessor::class);
    $processor->process($run, catalogRow([3 => 'I', 6 => '1']), $run->detail_columns, 3);
    $processor->process($run, catalogRow([3 => 'II', 6 => '1.0']), $run->detail_columns, 4);

    expect(Product::query()->count())->toBe(2)
        ->and(ProductImage::query()->count())->toBe(0)
        ->and(ImportLog::query()->where('message', 'Дефолтное изображение детали не найдено')->count())->toBe(1);
});

test('repeated import preserves manual product fields variants fitments and default images', function () {
    $firstRun = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
            ],
        ],
    ]);

    processCatalogRow($firstRun, catalogRow([6 => '1']));

    $product = Product::query()->firstOrFail();
    $variant = ProductVariant::query()->firstOrFail();
    $manual = ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => 'public',
        'path' => 'uploads/products/'.$product->getKey().'/manual-main.webp',
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
        'is_default' => false,
    ]);

    $legacyCategory = ProductCategory::factory()->create(['title' => 'Порог', 'slug' => 'legacy-porog']);
    $product->forceFill([
        'product_type' => ProductType::Generic,
        'part_type_id' => null,
        'product_category_id' => $legacyCategory->getKey(),
        'price' => 12345.67,
        'old_price' => 15000,
        'sku' => 'MANUAL-SKU',
        'description' => 'Ручное описание',
        'short_description' => 'Ручное краткое описание',
        'meta_title' => 'Ручной SEO title',
        'meta_description' => 'Ручной SEO description',
        'stock_status' => StockStatus::OutOfStock,
        'position' => 77,
        'is_featured' => true,
    ])->save();

    $variant->forceFill([
        'sku' => 'MANUAL-VARIANT-SKU',
        'price' => 7777.77,
        'stock_quantity' => 42,
        'stock_status' => StockStatus::OutOfStock,
    ])->save();

    $primaryFitment = ProductFitment::query()->firstOrFail();
    $primaryFitment->forceFill(['note' => 'Ручная заметка', 'is_primary' => false])->save();
    $additionalFitment = ProductFitment::factory()->forProduct($product)->create([
        'note' => 'Дополнительная применимость',
        'is_primary' => false,
    ]);
    $importImage = ProductImage::factory()->forProduct($product)->create([
        'source_type' => ProductImage::SOURCE_IMPORT,
        'source_url' => 'https://example.test/existing-import.jpg',
        'is_main' => false,
        'is_visible' => true,
    ]);

    $secondRun = catalogImportRun(['detail_columns' => $firstRun->detail_columns]);
    processCatalogRow($secondRun, catalogRow([6 => '1.0']));

    expect(Product::query()->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1)
        ->and(ProductFitment::query()->count())->toBe(2)
        ->and(ProductImage::query()->where('source_type', ProductImage::SOURCE_DEFAULT)->count())->toBe(1)
        ->and(ProductImage::query()->where('source_type', ProductImage::SOURCE_IMPORT)->count())->toBe(1)
        ->and($product->fresh()->product_type)->toBe(ProductType::AutoPart)
        ->and($product->fresh()->partType->full_slug)->toBe('porog')
        ->and($product->fresh()->category->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova/porogi')
        ->and($product->fresh()->last_import_run_id)->toBe((string) $secondRun->getKey())
        ->and($product->fresh()->price)->toEqual('12345.67')
        ->and($product->fresh()->old_price)->toEqual('15000.00')
        ->and($product->fresh()->sku)->toBe('MANUAL-SKU')
        ->and($product->fresh()->description)->toBe('Ручное описание')
        ->and($product->fresh()->short_description)->toBe('Ручное краткое описание')
        ->and($product->fresh()->meta_title)->toBe('Ручной SEO title')
        ->and($product->fresh()->meta_description)->toBe('Ручной SEO description')
        ->and($product->fresh()->stock_status)->toBe(StockStatus::OutOfStock)
        ->and($product->fresh()->position)->toBe(77)
        ->and($product->fresh()->is_featured)->toBeTrue()
        ->and($variant->fresh()->sku)->toBe('MANUAL-VARIANT-SKU')
        ->and($variant->fresh()->price)->toEqual('7777.77')
        ->and($variant->fresh()->stock_quantity)->toBe(42)
        ->and($variant->fresh()->stock_status)->toBe(StockStatus::OutOfStock)
        ->and($primaryFitment->fresh()->note)->toBe('Ручная заметка')
        ->and($primaryFitment->fresh()->is_primary)->toBeFalse()
        ->and($additionalFitment->fresh()->note)->toBe('Дополнительная применимость')
        ->and($manual->fresh()->is_main)->toBeTrue()
        ->and($importImage->fresh()->source_url)->toBe('https://example.test/existing-import.jpg');
});

test('manual product and another import source are not archived by catalog import', function () {
    $manualProduct = Product::factory()->create([
        'import_key' => null,
        'import_source' => null,
        'status' => ProductStatus::Active,
    ]);
    $otherSource = Product::factory()->create([
        'import_key' => 'supplier:old:product',
        'import_source' => 'supplier',
        'last_import_run_id' => 'old',
        'status' => ProductStatus::Active,
    ]);
    $oldCatalog = Product::factory()->create([
        'import_key' => 'catalog:old:product',
        'import_source' => 'catalog',
        'last_import_run_id' => 'old',
        'status' => ProductStatus::Active,
    ]);

    $run = catalogImportRun(['status' => ImportRunStatus::RunningRows, 'total_rows' => 1]);

    expect(app(ImportProductFactory::class)->archiveMissingProducts($run))->toBe(1)
        ->and($oldCatalog->fresh()->status)->toBe(ProductStatus::Archived)
        ->and($manualProduct->fresh()->status)->toBe(ProductStatus::Active)
        ->and($otherSource->fresh()->status)->toBe(ProductStatus::Active);
});

test('changed product image url queues new import image and disappeared url keeps manual and import images', function () {
    Storage::fake('public');
    Queue::fake();

    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Порог',
                'detail_title' => 'Порог',
                'full_detail_title' => 'Порог',
                'category_title' => 'Порог',
            ],
        ],
    ]);

    processCatalogRow($run, catalogRow([6 => 'https://example.test/old.jpg']));
    $product = Product::query()->firstOrFail();
    Storage::disk('public')->put('uploads/products/'.$product->getKey().'/old.webp', test_image_binary('webp'));
    $import = ProductImage::factory()->forProduct($product)->main()->create([
        'disk' => 'public',
        'path' => 'uploads/products/'.$product->getKey().'/old.webp',
        'source_type' => ProductImage::SOURCE_IMPORT,
        'source_url' => 'https://example.test/old.jpg',
        'is_visible' => true,
    ]);
    $manual = ProductImage::factory()->forProduct($product)->create([
        'disk' => 'public',
        'path' => 'uploads/products/'.$product->getKey().'/manual.webp',
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_visible' => true,
    ]);

    Queue::fake();
    app(ImportRowProcessor::class)->process($run, catalogRow([6 => 'https://example.test/new.jpg']), $run->detail_columns, 4);

    Queue::assertPushed(DownloadProductImageJob::class, fn (DownloadProductImageJob $job): bool => $job->url === 'https://example.test/new.jpg');

    app(ImportRowProcessor::class)->process($run, catalogRow([6 => '1']), $run->detail_columns, 5);

    expect($import->fresh())->not->toBeNull()
        ->and($manual->fresh())->not->toBeNull()
        ->and(ProductImage::query()->where('source_type', ProductImage::SOURCE_IMPORT)->count())->toBe(1)
        ->and(ProductImage::query()->where('source_type', ProductImage::SOURCE_MANUAL)->count())->toBe(1)
        ->and(ImportLog::query()->where('message', 'URL изображения товара исчез из файла импорта, существующие изображения сохранены')->count())->toBe(1);
});

test('vehicle generation image repeat import skips same url updates changed url and protects manual image', function () {
    Storage::fake('public');
    Queue::fake();

    $run = catalogImportRun();
    app(ImportRowProcessor::class)->process($run, catalogRow([0 => 'https://example.test/car-old.jpg', 6 => '']), $run->detail_columns, 3);
    Queue::assertPushed(DownloadVehicleGenerationImageJob::class);

    $generation = VehicleGeneration::query()->firstOrFail();
    Storage::disk('public')->put('uploads/vehicles/generations/'.$generation->getKey().'/old.webp', test_image_binary('webp'));
    $generation->forceFill([
        'image' => 'uploads/vehicles/generations/'.$generation->getKey().'/old.webp',
        'image_source_url' => 'https://example.test/car-old.jpg',
    ])->saveQuietly();

    Queue::fake();
    app(ImportRowProcessor::class)->process($run, catalogRow([0 => 'https://example.test/car-old.jpg', 6 => '']), $run->detail_columns, 4);
    Queue::assertNotPushed(DownloadVehicleGenerationImageJob::class);

    app(ImportRowProcessor::class)->process($run, catalogRow([0 => 'https://example.test/car-new.jpg', 6 => '']), $run->detail_columns, 5);
    Queue::assertPushed(DownloadVehicleGenerationImageJob::class, fn (DownloadVehicleGenerationImageJob $job): bool => $job->url === 'https://example.test/car-new.jpg');

    $manualGeneration = VehicleGeneration::factory()->create([
        'image' => 'uploads/vehicles/generations/manual.webp',
        'image_source_url' => null,
    ]);
    Storage::disk('public')->put('uploads/vehicles/generations/manual.webp', test_image_binary('webp'));

    app(ImportRowProcessor::class)->vehicleGeneration(
        makeTitle: $manualGeneration->model->make->title,
        modelTitle: $manualGeneration->model->title,
        generationTitle: $manualGeneration->title,
        years: $manualGeneration->years_label,
        body: $manualGeneration->body,
        sourceImageUrl: 'https://example.test/manual-protected.jpg',
        run: $run,
        rowNumber: 6,
    );

    expect($manualGeneration->fresh()->image)->toBe('uploads/vehicles/generations/manual.webp')
        ->and($manualGeneration->fresh()->image_source_url)->toBeNull()
        ->and(ImportLog::query()->where('message', 'Ручное фото поколения авто не перезаписано импортом')->exists())->toBeTrue();
});

test('prepare detail columns stores PartType and canonical store category fields idempotently', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            10 => [
                'index' => 10,
                'group' => 'Арка',
                'parent_title' => 'Арка',
                'title' => 'Внутренняя универсальная',
                'detail_title' => 'Внутренняя универсальная',
                'full_detail_title' => 'Арка внутренняя универсальная',
                'category_title' => 'Внутренняя универсальная',
            ],
        ],
    ]);
    $processor = app(ImportRowProcessor::class);

    $first = $processor->prepareDetailColumns($run, $run->detail_columns);
    $partTypeCount = PartType::query()->count();
    $second = $processor->prepareDetailColumns($run, $run->detail_columns);

    expect($first[10])->toMatchArray([
        'part_type_full_slug' => 'arka/vnutrenniaia-universalnaia',
        'part_type_full_title' => 'Арка / Внутренняя универсальная',
        'product_category_full_slug' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'product_category_full_path' => 'Кузовные детали / Ремонтные элементы кузова / Арки',
        'part_type_used_fallback' => false,
    ])->and($first[10]['part_type_id'])->toBeInt()
        ->and($first[10]['product_category_id'])->toBeInt()
        ->and(array_key_exists('category_id', $first[10]))->toBeFalse()
        ->and(array_key_exists('category_full_slug', $first[10]))->toBeFalse()
        ->and(array_key_exists('category_full_path', $first[10]))->toBeFalse()
        ->and($second[10]['part_type_id'])->toBe($first[10]['part_type_id'])
        ->and($second[10]['product_category_id'])->toBe($first[10]['product_category_id'])
        ->and(PartType::query()->count())->toBe($partTypeCount)
        ->and(ProductCategory::query()->count())->toBe(9)
        ->and($run->fresh()->created_categories)->toBe(0);
});

test('known Excel detail paths map to exact PartTypes and canonical store categories', function (
    ?string $parentTitle,
    string $detailTitle,
    string $expectedPartType,
    string $expectedStoreCategory,
) {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => $parentTitle,
                'parent_title' => $parentTitle,
                'title' => $detailTitle,
                'detail_title' => $detailTitle,
                'full_detail_title' => $parentTitle === null
                    ? $detailTitle
                    : $parentTitle.' '.mb_strtolower(mb_substr($detailTitle, 0, 1)).mb_substr($detailTitle, 1),
                'category_title' => $detailTitle,
            ],
        ],
    ]);
    $processor = app(ImportRowProcessor::class);
    $prepared = $processor->prepareDetailColumns($run, $run->detail_columns);

    $processor->process($run, catalogRow([6 => '1']), $prepared, 3);

    $product = Product::query()->with(['partType', 'category'])->firstOrFail();

    expect($prepared[6]['part_type_full_slug'])->toBe($expectedPartType)
        ->and($prepared[6]['product_category_full_slug'])->toBe($expectedStoreCategory)
        ->and($product->product_type)->toBe(ProductType::AutoPart)
        ->and($product->partType->full_slug)->toBe($expectedPartType)
        ->and($product->category->full_slug)->toBe($expectedStoreCategory)
        ->and(ProductCategory::query()->where('full_slug', $expectedPartType)->exists())->toBeFalse();
})->with([
    'porog' => [null, 'Порог', 'porog', 'kuzovnye-detali/remontnye-elementy-kuzova/porogi'],
    'arka zadniaia' => ['Арка', 'Задняя', 'arka/zadniaia', 'kuzovnye-detali/remontnye-elementy-kuzova/arki'],
    'arka peredniaia' => ['Арка', 'Передняя', 'arka/peredniaia', 'kuzovnye-detali/remontnye-elementy-kuzova/arki'],
    'arka vnutrenniaia' => ['Арка', 'Внутренняя', 'arka/vnutrenniaia', 'kuzovnye-detali/remontnye-elementy-kuzova/arki'],
    'arka vnutrenniaia universalnaia' => ['Арка', 'Внутренняя универсальная', 'arka/vnutrenniaia-universalnaia', 'kuzovnye-detali/remontnye-elementy-kuzova/arki'],
    'arka karman zadniaia' => ['Арка', 'Карман задняя', 'arka/karman-zadniaia', 'kuzovnye-detali/remontnye-elementy-kuzova/arki'],
    'penka root only' => [null, 'Пенка', 'penka', 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki'],
    'penka zadnei dveri' => ['Пенка', 'Задней двери', 'penka/zadnei-dveri', 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki'],
    'penka perednei dveri' => ['Пенка', 'Передней двери', 'penka/perednei-dveri', 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki'],
    'penka bagazhnika' => ['Пенка', 'Багажника', 'penka/bagazhnika', 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki'],
    'lonzheron' => [null, 'Лонжерон', 'lonzheron', 'kuzovnye-detali/remontnye-elementy-kuzova/lonzherony'],
    'floor repair kit' => [null, 'Ремкомплект пола', 'remkomplekt-pola', 'kuzovnye-detali/remontnye-elementy-kuzova/remkomplekty-pola'],
    'end cap' => [null, 'Торцевая заглушка', 'tortsevaia-zaglushka', 'kuzovnye-detali/remontnye-elementy-kuzova/zaglushki'],
    'reinforcement connector' => ['Усилитель', 'соединитель порогов', 'usilitel/soedinitel-porogov', 'kuzovnye-detali/remontnye-elementy-kuzova/usiliteli'],
]);

test('unknown child creates one PartType and logs fallback once for the import run', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => 'Арка',
                'parent_title' => 'Арка',
                'title' => 'Передняя усиленная',
                'detail_title' => 'Передняя усиленная',
                'full_detail_title' => 'Арка передняя усиленная',
                'category_title' => 'Передняя усиленная',
            ],
        ],
    ]);
    $processor = app(ImportRowProcessor::class);
    $prepared = $processor->prepareDetailColumns($run, $run->detail_columns);

    $processor->process($run, catalogRow([3 => 'XV70', 6 => '1']), $prepared, 3);
    $processor->process($run, catalogRow([3 => 'XV71', 6 => '1']), $prepared, 4);

    $partType = PartType::query()->where('full_slug', 'arka/peredniaia-usilennaia')->firstOrFail();
    $rootPartType = PartType::query()->where('full_slug', 'arka')->firstOrFail();
    $products = Product::query()->orderBy('id')->get();
    $archesCategoryId = ProductCategory::query()
        ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova/arki')
        ->value('id');

    expect($prepared[6]['part_type_used_fallback'])->toBeTrue()
        ->and($partType->default_image_key)->toBeNull()
        ->and($rootPartType->product_category_id)->toBe($archesCategoryId)
        ->and(PartType::query()->where('full_slug', 'arka')->count())->toBe(1)
        ->and(PartType::query()->where('full_slug', 'arka/peredniaia-usilennaia')->count())->toBe(1)
        ->and($products)->toHaveCount(2)
        ->and($products->pluck('part_type_id')->unique()->values()->all())->toBe([$partType->getKey()])
        ->and($products->pluck('product_category_id')->unique()->values()->all())->toBe([
            ProductCategory::query()->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova')->value('id'),
        ])->and($products->pluck('import_key')->unique())->toHaveCount(2)
        ->and(ImportLog::query()->where('message', 'Для нового типа детали использована резервная категория магазина')->count())->toBe(1)
        ->and(ProductCategory::query()->whereIn('title', ['Арка', 'Передняя усиленная'])->exists())->toBeFalse();
});

test('unknown root creates a fallback PartType without creating a technical ProductCategory', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            6 => [
                'index' => 6,
                'group' => null,
                'parent_title' => null,
                'title' => 'Новая кузовная деталь',
                'detail_title' => 'Новая кузовная деталь',
                'full_detail_title' => 'Новая кузовная деталь',
                'category_title' => 'Новая кузовная деталь',
            ],
        ],
    ]);

    processCatalogRow($run, catalogRow([6 => '1']));

    $partType = PartType::query()->where('title', 'Новая кузовная деталь')->firstOrFail();
    $product = Product::query()->firstOrFail();

    expect($partType->default_image_key)->toBeNull()
        ->and($product->part_type_id)->toBe($partType->getKey())
        ->and($product->category->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova')
        ->and(ProductCategory::query()->where('title', 'Новая кузовная деталь')->exists())->toBeFalse()
        ->and(ImportLog::query()->where('message', 'Для нового типа детали использована резервная категория магазина')->count())->toBe(1);
});

test('PartType import identity stays compatible with the former technical category path', function () {
    $run = catalogImportRun([
        'detail_columns' => [
            10 => [
                'index' => 10,
                'group' => 'Арка',
                'parent_title' => 'Арка',
                'title' => 'Внутренняя универсальная',
                'detail_title' => 'Внутренняя универсальная',
                'full_detail_title' => 'Арка внутренняя универсальная',
                'category_title' => 'Внутренняя универсальная',
            ],
        ],
    ]);
    $processor = app(ImportRowProcessor::class);
    $prepared = $processor->prepareDetailColumns($run, $run->detail_columns);
    $partType = PartType::query()->findOrFail($prepared[10]['part_type_id']);
    $generation = $processor->vehicleGeneration('Audi', 'A4', 'B5', '1994–2001', 'Универсал');
    $factory = app(ImportProductFactory::class);
    $oldImportKey = CatalogText::stableKey([
        'catalog',
        $generation->model->make->norm_key,
        $generation->model->norm_key,
        $generation->norm_key,
        'arka',
        'vnutrenniaia-universalnaia',
    ], ':', 240, 'catalog');
    $newImportKey = $factory->importKey($generation, $partType, 'catalog');
    $stableTitle = $factory->productTitle($partType, $generation);
    $stableSlug = $factory->stableSlug($generation, $partType, 'catalog', $stableTitle);
    $legacyRoot = ProductCategory::factory()->create(['title' => 'Арка', 'slug' => 'arka']);
    $legacyChild = ProductCategory::factory()->forParent($legacyRoot)->create([
        'title' => 'Внутренняя универсальная',
        'slug' => 'vnutrenniaia-universalnaia',
    ]);
    $existing = Product::factory()->forCategory($legacyChild)->create([
        'product_type' => ProductType::Generic,
        'part_type_id' => null,
        'title' => $stableTitle,
        'slug' => $stableSlug,
        'import_key' => $oldImportKey,
        'import_source' => 'catalog',
        'last_import_run_id' => 'old-run',
    ]);

    $processor->process(
        $run,
        catalogRow([1 => 'Audi', 2 => 'A4', 3 => 'B5', 4 => '1994–2001', 5 => 'Универсал', 6 => '', 10 => '1']),
        $prepared,
        3,
    );

    $existing->refresh();
    $archived = $factory->archiveMissingProducts($run->fresh());

    expect($newImportKey)->toBe($oldImportKey)
        ->and($archived)->toBe(0)
        ->and(Product::query()->count())->toBe(1)
        ->and($existing->getKey())->toBe(Product::query()->value('id'))
        ->and($existing->import_key)->toBe($oldImportKey)
        ->and($existing->slug)->toBe($stableSlug)
        ->and($existing->part_type_id)->toBe($partType->getKey())
        ->and($existing->product_category_id)->toBe($prepared[10]['product_category_id'])
        ->and($existing->product_type)->toBe(ProductType::AutoPart)
        ->and($existing->status)->toBe(ProductStatus::Active);
});

test('missing prepared ids are safely resolved again from header text', function () {
    $run = catalogImportRun();
    $processor = app(ImportRowProcessor::class);
    $prepared = $processor->prepareDetailColumns($run, $run->detail_columns);
    $prepared[6]['part_type_id'] = 999999;
    $prepared[6]['product_category_id'] = 999999;

    $processor->process($run, catalogRow([6 => '1']), $prepared, 3);

    $product = Product::query()->with(['partType', 'category'])->firstOrFail();

    expect($product->partType->full_slug)->toBe('porog')
        ->and($product->category->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova/porogi')
        ->and(ImportLog::query()->where('message', 'Подготовленные данные типа детали устарели, выполнено повторное разрешение')->count())->toBe(1);
});

test('import resolver restores soft deleted PartType without replacing manual metadata', function () {
    $partType = PartType::factory()->create([
        'title' => 'Порог',
        'default_image_key' => 'manual-porog-key',
        'meta_title' => 'Ручной title',
        'meta_description' => 'Ручное description',
        'is_active' => false,
    ]);
    $partTypeId = $partType->getKey();
    $partType->delete();
    $run = catalogImportRun();

    $prepared = app(ImportRowProcessor::class)->prepareDetailColumns($run, $run->detail_columns);
    $restored = PartType::query()->findOrFail($partTypeId);

    expect($prepared[6]['part_type_id'])->toBe($partTypeId)
        ->and(PartType::withTrashed()->where('full_slug', 'porog')->count())->toBe(1)
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->is_active)->toBeTrue()
        ->and($restored->default_image_key)->toBe('manual-porog-key')
        ->and($restored->meta_title)->toBe('Ручной title')
        ->and($restored->meta_description)->toBe('Ручное description')
        ->and(ImportLog::query()->where('message', 'Восстановлен ранее удалённый тип детали')->count())->toBe(1);
});
