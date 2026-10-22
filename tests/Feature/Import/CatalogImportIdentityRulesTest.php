<?php

use App\Models\PartType;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Import\CatalogImportExpectedProducts;
use App\Services\Import\CatalogImportIdentity;
use App\Services\Import\CatalogImportProductPlan;
use App\Services\Import\ImportProductFactory;
use App\Services\Import\ImportRowProcessor;
use App\Services\SpreadsheetReader;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Bus::fake();
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
});

function id1Workbook(string $sheet = 'Произвольное название', ?array $rows = null, ?array $headers = null, bool $multiple = false): string
{
    $path = Storage::disk('local')->path(uniqid('id1-').'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName($sheet);
    $writer->addRow(Row::fromValues(array_fill(0, 9, null)));
    $writer->addRow(Row::fromValues($headers ?? ['Фото', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Усилитель', 'Рестайлинг', 'Арка / Задняя']));
    foreach ($rows ?? [[null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1, '2 дорест / 2 рест', 0]] as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    if ($multiple) {
        $writer->addNewSheetAndMakeItCurrent()->setName('Каталог (new)');
        $writer->addRow(Row::fromValues(['Other table']));
    }
    $writer->close();

    return $path;
}

function id1Generation(array $extra = []): VehicleGeneration
{
    $make = VehicleMake::firstOrCreate(['norm_key' => 'acura'], ['title' => 'Acura', 'slug' => 'acura', 'is_active' => true]);
    $model = VehicleModel::firstOrCreate(['vehicle_make_id' => $make->id, 'norm_key' => 'mdx'], ['title' => 'MDX', 'slug' => 'mdx', 'is_active' => true]);

    return VehicleGeneration::factory()->create(array_replace([
        'vehicle_model_id' => $model->id, 'title' => '2', 'years_label' => '2006–2013', 'body' => 'Внедорожник',
        'norm_key' => 'historical-generation-22', 'slug' => 'historical-car-url', 'restyling' => null,
    ], $extra));
}

function id1Product(VehicleGeneration $generation, array $extra = []): Product
{
    $p = Product::factory()->create(array_replace([
        'part_type_id' => PartType::where('full_slug', 'usilitel')->value('id'),
        'import_source' => 'catalog', 'import_key' => 'catalog:historical-product-88',
        'slug' => 'historical-product-url', 'price_mode' => 'unknown', 'status' => 'active',
    ], $extra));
    DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $generation->id, 'is_primary' => true]);

    return $p;
}

test('a single catalog worksheet is independent of its name', function (string $name): void {
    $path = id1Workbook($name);
    $reader = app(SpreadsheetReader::class);
    $plan = app(CatalogImportProductPlan::class)->plan($path);
    $prepared = $reader->prepareRows($path);
    expect($plan['valid'])->toBeTrue()->and($plan['file']['sheet'])->toBe($name)
        ->and($prepared['sheet'])->toBe($name)->and($prepared['identity_version'])->toBe(1)
        ->and($plan['counts']['expected'])->toBe(1);
})->with(['Каталог', 'Каталог (new)', 'Поставка 2026']);

test('worksheet relationship is resolved without assuming sheet number one', function (): void {
    $path = id1Workbook();
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString('xl/worksheets/sheet7.xml', $zip->getFromName('xl/worksheets/sheet1.xml'));
    $zip->deleteName('xl/worksheets/sheet1.xml');
    $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('sheet1.xml', 'sheet7.xml', $zip->getFromName('xl/_rels/workbook.xml.rels')));
    $zip->addFromString('xl/workbook.xml', str_replace('sheetId="1"', 'sheetId="7"', $zip->getFromName('xl/workbook.xml')));
    $zip->close();
    expect(app(CatalogImportProductPlan::class)->plan($path)['counts']['expected'])->toBe(1);
});

test('multiple worksheets are rejected even when one has the old required name', function (): void {
    $path = id1Workbook(multiple: true);
    expect(fn () => app(CatalogImportProductPlan::class)->plan($path))->toThrow(InvalidArgumentException::class, 'один рабочий лист');
});

test('required metadata and unknown conflicting product headers remain validated', function (array $headers): void {
    expect(fn () => app(CatalogImportProductPlan::class)->plan(id1Workbook(headers: $headers)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    [['Фото', 'Марка', 'Модель', '', 'Годы выпуска', 'Кузов', 'Усилитель']],
    [['Фото', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Усилитель', '', 'Усилитель']],
]);

test('photo URLs and the legacy missing image designation survive single sheet reading', function (): void {
    $headers = ['', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Усилитель'];
    $path = id1Workbook(rows: [
        ['нет изображения', 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1],
        ['https://example.test/car.jpg', 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1],
    ], headers: $headers);
    $rows = iterator_to_array(app(SpreadsheetReader::class)->catalogRows($path));
    expect($rows[0]['row']['_photo_urls'])->toBe([])
        ->and($rows[1]['row']['_photo_urls'])->toBe(['https://example.test/car.jpg']);
});

test('combined restyling is metadata and does not add a second applicability', function (): void {
    $path = id1Workbook();
    $row = iterator_to_array(app(SpreadsheetReader::class)->catalogRows($path))[0]['row'];
    expect($row[3])->toBe('2')->and($row['_restyling'])->toBe('2 дорест / 2 рест');
    $plan = app(CatalogImportProductPlan::class)->plan($path);
    expect($plan['counts']['expected'])->toBe(1)->and($plan['expected_products'][0]['import_key'])
        ->toBe(CatalogImportIdentity::fromSegments('catalog', 'acura', 'mdx', '2-2006-2013-vnedoroznik', 'usilitel'));
});

test('historical product key ID slug and manual hiding survive an added description', function (?string $override): void {
    $generation = id1Generation();
    $product = id1Product($generation, ['publication_override' => $override, 'status' => $override ?? 'active']);
    $path = id1Workbook();
    $before = $product->fresh()->getRawOriginal();
    $planner = app(CatalogImportProductPlan::class);
    $plan = $planner->plan($path);
    expect($plan['valid'])->toBeTrue()->and($plan['counts'])->toMatchArray(['new' => 0, 'existing' => 1, 'historical_matches' => 1])
        ->and($plan['expected_products'][0]['product_id'])->toBe($product->id)
        ->and($plan['expected_products'][0]['import_key'])->toBe('catalog:historical-product-88')
        ->and($plan['expected_products'][0]['action'])->toBe($override ? 'preserve_manual' : 'keep')
        ->and($planner->plan($path))->toBe($plan)->and($product->fresh()->getRawOriginal())->toBe($before);
    $row = iterator_to_array(app(SpreadsheetReader::class)->catalogRows($path))[0]['row'];
    $actual = app(ImportRowProcessor::class)->vehicleGeneration('Acura', 'MDX', $row[3], $row[4], $row[5], modern: true, restyling: $row['_restyling']);
    $factoryKey = app(ImportProductFactory::class)->importKey($actual, $product->partType);
    expect($actual->id)->toBe($generation->id)->and($actual->restyling)->toBe('2 дорест / 2 рест')
        ->and($factoryKey)->toBe($plan['expected_products'][0]['import_key'])
        ->and(Product::where('import_key', $factoryKey)->firstOrFail()->id)->toBe($product->id);
})->with([null, 'archived', 'draft']);

test('separate versions with different availability remain different vehicles and product keys', function (): void {
    $path = id1Workbook(rows: [
        [null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1, '2 дорест', 0],
        [null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 0, '2 рест', 1],
    ]);
    $plan = app(CatalogImportProductPlan::class)->plan($path);
    expect($plan['valid'])->toBeTrue()->and($plan['counts']['expected'])->toBe(2);
    $inspection = app(\App\Services\Import\ImportFileInspector::class)->inspect($path);
    expect($inspection['generations'])->toBe(2)->and($inspection['unique_products'])->toBe(2);
    $processor = app(ImportRowProcessor::class);
    $ids = [];
    foreach (app(SpreadsheetReader::class)->catalogRows($path) as $entry) {
        $row = $entry['row'];
        $ids[] = $processor->vehicleGeneration('Acura', 'MDX', $row[3], $row[4], $row[5], modern: true, restyling: $row['_restyling'])->id;
    }
    expect(array_unique($ids))->toHaveCount(2)->and(VehicleGeneration::count())->toBe(2);
});

test('duplicate shared descriptions combine presence without duplicate product keys', function (): void {
    $row = [null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1, '2 дорест / 2 рест', 0];
    $plan = app(CatalogImportProductPlan::class)->plan(id1Workbook(rows: [$row, array_replace($row, [7 => '2дорест/2рест'])]));
    expect($plan['valid'])->toBeTrue()->and($plan['counts']['expected'])->toBe(1)
        ->and($plan['diagnostics']['duplicate_positive_cells'])->toBe(1);
});

test('competing separate versions cannot claim one historical applicability even with disjoint parts', function (): void {
    id1Product(id1Generation(), ['status' => 'archived']);
    $path = id1Workbook(rows: [
        [null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1, '2 дорест', 0],
        [null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 0, '2 рест', 1],
    ]);
    $plan = app(CatalogImportProductPlan::class)->plan($path);
    expect($plan['valid'])->toBeFalse()->and($plan['counts']['new'])->toBeNull()->and($plan['counts']['archive'])->toBeNull()
        ->and($plan['counts']['reactivate'])->toBe(0)->and($plan['counts']['existing'])->toBe(0);
    $rows = [];
    foreach (app(SpreadsheetReader::class)->catalogRows($path) as $entry) {
        $rows[] = $entry['row'] + ['_row_number' => $entry['number']];
    }
    expect(fn () => app(CatalogImportExpectedProducts::class)->collect($rows, app(SpreadsheetReader::class)->readMergedDetailHeaders($path), 'catalog'))
        ->toThrow(RuntimeException::class, 'Коллизия');
});

test('two historical vehicle or product candidates are never chosen arbitrarily', function (string $kind): void {
    $generation = id1Generation();
    id1Product($generation);
    if ($kind === 'vehicle') {
        id1Generation(['norm_key' => 'other-generation', 'slug' => 'other-car-url']);
    } else {
        id1Product($generation, ['import_key' => 'catalog:other-historical-product', 'slug' => 'other-product-url']);
    }
    $plan = app(CatalogImportProductPlan::class)->plan(id1Workbook());
    expect($plan['valid'])->toBeFalse()->and($plan['diagnostics']['identity_error_count'] ?? $plan['diagnostics']['error_count'])->toBeGreaterThan(0)
        ->and($plan['counts']['new'])->toBeNull()->and($plan['counts']['archive'])->toBeNull()
        ->and($plan['diagnostics']['errors'][0][$kind === 'vehicle' ? 'candidate_vehicle_ids' : 'old_candidates'])->toHaveCount(2);
})->with(['vehicle', 'product']);

test('read-only planning leaves every table file price image and queue unchanged', function (): void {
    id1Product(id1Generation());
    $path = id1Workbook();
    $state = fn () => collect(DB::getSchemaBuilder()->getTableListing())->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
    $before = $state();
    $files = Storage::disk('local')->allFiles();
    $hash = hash_file('sha256', $path);
    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });
    $plan = app(CatalogImportProductPlan::class)->plan($path);
    expect($plan['valid'])->toBeTrue()->and($state())->toBe($before)->and($writes)->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe($files)->and(hash_file('sha256', $path))->toBe($hash);
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('a conflicting generation number is diagnosed even when an exact staged vehicle exists', function (): void {
    $old = id1Generation(['title' => '1']);
    id1Product($old);
    id1Generation(['title' => '2', 'norm_key' => 'staged-other-generation', 'slug' => 'staged-other-car']);
    $plan = app(CatalogImportProductPlan::class)->plan(id1Workbook());
    expect($plan['valid'])->toBeFalse()->and($plan['counts']['archive'])->toBeNull()
        ->and($plan['diagnostics']['errors'][0]['code'])->toBe('generation_change')
        ->and($plan['diagnostics']['errors'][0]['old_candidates'][0]['import_key'])->toBe('catalog:historical-product-88');
});
