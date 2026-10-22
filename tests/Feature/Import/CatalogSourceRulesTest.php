<?php

use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportCsvStartJob;
use App\Jobs\CatalogImportImagesJob;
use App\Jobs\CatalogImportStartJob;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogPricingSchema;
use App\Services\Import\CatalogImportFormat;
use App\Services\Import\CatalogImportSnapshot;
use App\Services\Import\CatalogSourceContract;
use App\Services\Import\ImportFileInspector;
use App\Services\ImportStatusService;
use App\Services\Media\DefaultProductImageService;
use App\Services\SpreadsheetReader;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
});

function sourceRulesWorkbook(array $rows, bool $includeRequiredSheet = true, ?array $headers = null, bool $multiple = false): string
{
    $path = Storage::disk('local')->path('source-rules.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName($includeRequiredSheet ? 'Каталог (new)' : 'Copy of Каталог (new)');
    $writer->addRow(Row::fromValues(array_fill(0, 9, null)));
    $writer->addRow(Row::fromValues($headers ?? ['Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Платформа', 'Усилитель', 'Фото', 'Ссылка на фото автомобиля']));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    if ($multiple) {
        $writer->addNewSheetAndMakeItCurrent()->setName('Other');
        $writer->addRow(Row::fromValues(['Other table']));
    }
    $writer->close();

    return $path;
}

test('multiple working sheets are rejected regardless of their names', function () {
    $path = sourceRulesWorkbook([], false, multiple: true);
    expect(fn () => app(SpreadsheetReader::class)->prepareRows($path))->toThrow(InvalidArgumentException::class, 'один рабочий лист');
    expect(Product::count())->toBe(0)->and(VehicleGeneration::count())->toBe(0);
});

test('preview and prepared import skip incomplete vehicles with physical row numbers and accept missing photos', function () {
    $valid = ['Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 'YD2', true, null, null];
    $path = sourceRulesWorkbook([$valid, array_replace($valid, [2 => null]), array_replace($valid, [4 => '']),
        array_replace($valid, [7 => 'https://example.test/first.jpg', 8 => 'https://example.test/second.jpg'])]);
    $reader = app(SpreadsheetReader::class);
    $prepared = $reader->prepareRows($path);
    $preview = app(ImportFileInspector::class)->inspect($path);
    $rows = $reader->readPreparedChunk($prepared, 0, 10);
    expect($prepared['sheet'])->toBe(CatalogImportFormat::SHEET_NAME)
        ->and($prepared['offsets'])->toHaveCount(2)
        ->and($preview['data_rows'])->toBe(2)
        ->and($preview['skipped_rows'])->toBe($prepared['skipped_rows'])
        ->and(array_column($prepared['skipped_rows'], 'row'))->toBe([4, 5])
        ->and($rows[0][0])->toBe('')
        ->and($rows[1]['_photo_urls'])->toBe(['https://example.test/first.jpg', 'https://example.test/second.jpg']);
});

test('start rejects missing matrix before vehicles or products can be changed', function (bool $missingTable) {
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    $path = sourceRulesWorkbook([['Acura', 'MDX', '2', '2006–2013', 'Внедорожник', null, 1]]);
    $products = Product::count();
    $vehicles = VehicleGeneration::count();
    if ($missingTable) {
        Schema::drop('catalog_configurations');
        app(CatalogPricingSchema::class)->forget(DB::connection());
    }
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($path), basename($path));
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    expect($run->fresh()->status->value)->toBe('failed')
        ->and(Product::count())->toBe($products)->and(VehicleGeneration::count())->toBe($vehicles);
})->with([false, true]);

test('a shared physical PNG default survives manual replacement and resolves old references', function () {
    $service = app(DefaultProductImageService::class);
    $default = $service->findByKey('regression-placeholder');
    expect(substr(file_get_contents($default['absolute_path']), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
    $replacement = test_image_binary('png');
    file_put_contents($default['absolute_path'], $replacement);
    expect($service->findByKey('regression-placeholder')['path'])->toBe($default['path'])
        ->and(file_get_contents($default['absolute_path']))->toBe($replacement)
        ->and($service->urlForPath('img/products_default/regression-placeholder.png'))->toBe($service->findByKey('regression-placeholder')['url']);
});

test('inner arch columns create independent products only for their own presence and repeat without duplicates', function (mixed $rear, mixed $front, int $expected) {
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    app(CatalogConfigurations::class)->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    $path = sourceRulesWorkbook([['Acura', 'MDX', '2', '2006–2013', 'Внедорожник', null, $rear, $front, 0]], headers: ['Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Платформа', 'Арка / Внутренняя задняя', 'Арка / Внутренняя передняя', 'Барабан']);
    $before = null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($path), basename($path));
        app()->call([new CatalogImportStartJob($run->id), 'handle']);
        app()->call([new CatalogImportChunkJob($run->id), 'handle']);
        expect($run->fresh()->status->value)->toBe('done')->and($run->fresh()->errors_count)->toBe(0);
        $products = Product::where('import_source', 'catalog')->with('partType', 'variants')->orderBy('id')->get();
        expect($products)->toHaveCount($expected);
        foreach ($products as $product) {
            expect($product->variants)->toHaveCount(6);
        }
        expect($products->contains(fn ($p) => $p->partType->full_slug === 'arka/vnutrenniaia-zadniaia'))->toBe((bool) $rear)
            ->and($products->contains(fn ($p) => $p->partType->full_slug === 'arka/vnutrenniaia-peredniaia'))->toBe((bool) $front);
        $identity = $products->map(fn ($p) => [$p->id, $p->slug, $p->variants->modelKeys()])->all();
        if ($before !== null) {
            expect($identity)->toBe($before)->and($run->fresh()->created_products)->toBe(0);
        }
        $before = $identity;
    }
})->with([[1, 0, 1], [0, true, 1], [true, 1, 2]]);

test('failed vehicle photo uses an alternative and replay neither redownloads nor duplicates counters', function () {
    fake_vehicle_photo_verifier(smallFixtures: true);
    $generation = VehicleGeneration::factory()->create(['years_label' => '2000-2005', 'body' => 'Седан']);
    $run = ImportRun::factory()->create(['status' => 'processing_images', 'queued_images' => 1]);
    Http::fake([
        'https://example.test/missing.jpg' => Http::response('', 404),
        'https://example.test/valid.png' => Http::response(test_image_binary('png'), 200, ['Content-Type' => 'image/png']),
    ]);
    $job = new DownloadVehicleGenerationImageJob($generation->id, 'https://example.test/missing.jpg', $run->id, ['https://example.test/valid.png']);
    app()->call([$job, 'handle']);
    $saved = $generation->fresh();
    expect($saved->image_source_url)->toBe('https://example.test/valid.png');
    Storage::disk('public')->assertExists($saved->image);
    $requests = Http::recorded()->count();
    app()->call([$job, 'handle']);
    expect(Http::recorded()->count())->toBe($requests)
        ->and($run->fresh()->processed_images)->toBe(1)->and($run->fresh()->failed_images)->toBe(0);
    $log = ImportLog::where('import_run_id', $run->id)->where('context->result', 'saved')->firstOrFail();
    expect($log->context['failed_sources'][0]['url'])->toBe('https://example.test/missing.jpg');
});

test('paired Excel and CSV are checked before creating a run or touching the catalogue', function () {
    $book = sourceRulesWorkbook([['Acura', 'MDX', '2', '2006–2013', 'Внедорожник', null, 1]]);
    $csv = Storage::disk('local')->path('paired-config.csv');
    file_put_contents($csv, "Название,Профиль,Положение,Материал,Толщина металла,Цена\nУсилитель,-,Левый,ХКС,1 мм,900\n");
    $result = app(CatalogSourceContract::class)->validate($book, $csv);
    expect($result['columns'])->toBe(1)->and($result['source_rows'])->toBe(1);
    file_put_contents($csv, "Название,Профиль,Положение,Материал,Толщина металла,Цена\nЛонжерон,-,Левый,ХКС,1 мм,900\n");
    expect(fn () => app(CatalogSourceContract::class)->validate($book, $csv))
        ->toThrow(ValidationException::class);
    expect(Product::count())->toBe(0);
});

test('CSV-only requires a prior successful Excel snapshot and never creates products on refusal', function () {
    $csv = Storage::disk('local')->path('only-config.csv');
    file_put_contents($csv, "Название,Профиль,Положение,Материал,Толщина металла,Цена\nУсилитель,-,Левый,ХКС,1 мм,900\n");
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($csv), basename($csv));
    $run->forceFill(['import_mode' => 'csv'])->save();
    app(ImportStatusService::class)->start($run);
    app()->call([new CatalogImportCsvStartJob($run->id), 'handle']);
    expect($run->fresh()->status->value)->toBe('failed')
        ->and($run->fresh()->last_error)->toContain('сначала завершите полный импорт')
        ->and(Product::count())->toBe(0);
});

test('image-only import never creates vehicle or product entities for an unmatched automobile', function () {
    $path = sourceRulesWorkbook([['UnknownMake', 'UnknownModel', 'Generation', '2000–2004', 'Седан', null, 0,
        'https://example.test/car.jpg']], headers: ['Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Платформа', 'Усилитель', 'Фото']);
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($path), basename($path));
    $run->forceFill(['import_mode' => 'images'])->save();
    app(ImportStatusService::class)->start($run);
    app()->call([new CatalogImportImagesJob($run->id), 'handle']);
    expect($run->fresh()->status->value)->toBe('done')
        ->and(VehicleMake::count())->toBe(0)
        ->and(Product::count())->toBe(0);
});

test('duplicate vehicle rows combine positive presence without creating duplicate snapshot items', function () {
    $path = sourceRulesWorkbook([
        ['Chevrolet', 'Lacetti', 'I', '2004–2013', 'Седан', null, 1, 0],
        ['chevrolet', 'Lacetti', 'I', '2004–2013', 'Седан', null, 0, true],
    ], headers: ['Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Платформа', 'Усилитель', 'Арка / Задняя']);
    $reader = app(SpreadsheetReader::class);
    $prepared = $reader->prepareRows($path);
    $headers = [
        6 => ['part_type_full_slug' => 'usilitel'],
        7 => ['part_type_full_slug' => 'arka/zadniaia'],
    ];
    $run = ImportRun::factory()->create(['type' => 'catalog']);
    $expected = app(CatalogImportSnapshot::class)->prepare($run, $prepared, $headers, $reader);
    expect($expected)->toBe(2)
        ->and(DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->count())->toBe(2)
        ->and(Product::count())->toBe(0);
});

test('image-only reader accepts a partial Excel with no product columns', function () {
    $path = sourceRulesWorkbook([
        ['Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 'YD2', 'https://example.test/car.png'],
    ], headers: ['Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Платформа', 'Фото']);
    $rows = iterator_to_array(app(SpreadsheetReader::class)->catalogImageRows($path));
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['row']['_photo_urls'])->toBe(['https://example.test/car.png']);
    expect(Product::count())->toBe(0);
});
