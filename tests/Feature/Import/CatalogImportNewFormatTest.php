<?php

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Jobs\PublishCatalogSearch;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogBulkPrices;
use App\Services\Import\CatalogImportCell;
use App\Services\Import\ImportImageDownloader;
use App\Services\Import\ImportProductFactory;
use App\Services\Import\ImportRowProcessor;
use App\Services\ImportStatusService;
use App\Services\Media\ImageDownloadService;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function (): void {
    if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('These import tests require isolated in-memory SQLite.');
    }
    // Real row commits keep the full workbook test bounded in memory, as in queue workers.
    $this->artisan('migrate:fresh', ['--force' => true]);
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class]);
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
});

function modernCatalogHeaders(): array
{
    return [
        1 => [6 => 'Порог', 8 => 'Арка', 12 => 'Пенка'],
        2 => [null, 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов',
            'Стандартный', 'Увеличенный', 'Задняя', 'Передняя', 'Внутренняя задняя',
            'Внутренняя передняя', 'Задней двери', 'Передней двери', 'Багажника',
            'Платформа', 'Рестайлинг', null, 'Ссылка на фото автомобиля'],
    ];
}

function modernCatalogRow(array $values = []): array
{
    return array_replace(array_fill(0, 19, null), [1 => 'Acura', 2 => 'MDX', 3 => 2,
        4 => '2006-2013', 5 => 'Внедорожник 5 дв.', 6 => 1, 15 => 'YD2'], $values);
}

function modernCatalogFile(array $rows, bool $withLegacy = false, ?array $columnHeaders = null): string
{
    $path = Storage::disk('local')->path('catalog-'.uniqid().'.xlsx');
    $options = new Options;
    foreach ([[6, 7], [8, 11], [12, 14]] as [$start, $end]) {
        $options->mergeCells($start, 1, $end, 1, $withLegacy ? 1 : 0);
    }
    $writer = new Writer($options);
    $writer->openToFile($path);
    if ($withLegacy) {
        $writer->getCurrentSheet()->setName('Каталог');
        $writer->addRow(Row::fromValues(['obsolete']));
        $writer->addRow(Row::fromValues(['must not be read']));
        $writer->addRow(Row::fromValues(['old data']));
        $writer->addNewSheetAndMakeItCurrent();
    }
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $headers = modernCatalogHeaders();
    $writer->addRow(Row::fromValues(array_replace(array_fill(0, 19, null), $headers[1])));
    $writer->addRow(Row::fromValues($columnHeaders ?? $headers[2]));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

function completeModernCatalogImport(string $path, int $chunkSize = 2): ImportRun
{
    $run = app(ImportStatusService::class)->createFromStoredPath(
        'catalog', basename($path), basename($path), chunkSize: $chunkSize,
    );
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    while ($run->refresh()->status === ImportRunStatus::RunningRows) {
        app()->call([new CatalogImportChunkJob($run->id), 'handle']);
        if ($chunkSize === 100) {
            fwrite(STDOUT, "\nImport {$run->id}: {$run->fresh()->processed_rows}/{$run->total_rows} rows\n");
        }
    }

    return $run->refresh();
}

test('broken default auto part contract fails before row processing or archive phase', function (): void {
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $profile = ProductOptionGroup::query()->where('code', 'profile')->firstOrFail();
    $legacy = $profile->values()->create([
        'title' => 'Увеличенный',
        'slug' => 'lncreased',
        'code' => 'lncreased',
        'is_active' => true,
    ]);
    $template->items()->create([
        'product_option_group_id' => $profile->getKey(),
        'product_option_value_id' => $legacy->getKey(),
        'position' => 999,
    ]);

    $productIdsBefore = Product::query()->orderBy('id')->pluck('id')->all();
    $partTypesBefore = PartType::query()->count();
    $path = modernCatalogFile([modernCatalogRow(), modernCatalogRow([2 => 'RDX'])]);

    $run = completeModernCatalogImport($path);

    expect($run->status)->toBe(ImportRunStatus::Failed)
        ->and($run->errors_count)->toBe(1)
        ->and($run->processed_rows)->toBe(0)
        ->and($run->total_rows)->toBe(0)
        ->and($run->initialized_at)->toBeNull()
        ->and($run->archive_skipped)->toBeFalse()
        ->and($run->last_error)->toContain('9 активных canonical selectable values')
        ->and($run->logs()->where('level', 'error')->count())->toBe(1)
        ->and($run->logs()->where('message', 'Ошибка обработки строки импорта')->count())->toBe(0)
        ->and(Product::query()->orderBy('id')->pluck('id')->all())->toBe($productIdsBefore)
        ->and(PartType::query()->count())->toBe($partTypesBefore);
});

test('snapshot chunks seek directly preserve source row numbers and detect truncation', function (): void {
    $path = modernCatalogFile([modernCatalogRow(), array_fill(0, 19, null), modernCatalogRow([2 => 'RDX', 15 => null])]);
    $reader = app(SpreadsheetReader::class);
    $prepared = $reader->prepareRows($path);
    expect($prepared['offsets'])->toHaveCount(2)
        ->and($prepared['snapshot_version'])->toBe(1);
    $rows = $reader->readPreparedChunk($prepared, 1, 10);
    expect($rows)->toHaveCount(1)->and($rows[0][2])->toBe('RDX')->and($rows[0]['_row_number'])->toBe(5);
    file_put_contents($prepared['path'], '');
    expect(fn () => $reader->readPreparedChunk($prepared, 0, 10))->toThrow(RuntimeException::class);
});

test('invalid required fields fail preparation before catalog changes or pruning', function (): void {
    completeModernCatalogImport(modernCatalogFile([modernCatalogRow()]));
    $ids = Product::pluck('id')->all();
    $run = completeModernCatalogImport(modernCatalogFile([modernCatalogRow([1 => null, 18 => null])]));
    expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->last_error)->toContain('Строка 3')
        ->and(Product::where('status', ProductStatus::Active)->pluck('id')->all())->toBe($ids);
});

test('row errors prevent both product archiving and vehicle pruning', function (): void {
    $first = completeModernCatalogImport(modernCatalogFile([modernCatalogRow()]));
    $this->assertSame(0, $first->errors_count, $first->last_error ?? $first->logs()->get()->toJson());
    $run = ImportRun::factory()->create(['type' => 'catalog', 'status' => ImportRunStatus::RunningRows,
        'total_rows' => 1, 'errors_count' => 1]);
    expect(app(ImportProductFactory::class)->archiveMissingProducts($run))->toBe(0)
        ->and(Product::where('status', ProductStatus::Active)->count())->toBe(1)
        ->and(VehicleGeneration::where('is_active', true)->count())->toBe(1);
});

test('public disk photo resolves through API and preserves its stable source url', function (): void {
    Storage::fake('public');
    $publicUrl = 'https://yadi.sk/i/example';
    Http::fake([
        'cloud-api.yandex.net/*' => Http::response(['href' => 'https://downloader.disk.yandex.ru/file.jpg']),
        'downloader.disk.yandex.ru/*' => Http::response(test_image_binary(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $image = app(ImageDownloadService::class)->download($publicUrl, 'vehicle_image', 'uploads/test');
    expect($image->originalUrl)->toBe($publicUrl);
    Http::assertSentCount(2);
});

test('catalog page photo follows only its image metadata and public disk failures do not save files', function (): void {
    Storage::fake('public');
    Http::fake([
        'www.drom.ru/*' => Http::response('<html><head><meta property="og:image" content="https://images.example.test/car.jpg"></head></html>', 200, ['Content-Type' => 'text/html']),
        'images.example.test/*' => Http::response(test_image_binary(), 200, ['Content-Type' => 'image/jpeg']),
        'cloud-api.yandex.net/*' => Http::response(['href' => 'file:///etc/passwd']),
    ]);
    $image = app(ImageDownloadService::class)->download('https://www.drom.ru/catalog/acura/mdx/', 'vehicle_image', 'uploads/test');
    expect($image->originalUrl)->toBe('https://www.drom.ru/catalog/acura/mdx/');
    $files = Storage::disk('public')->allFiles();
    expect(fn () => app(ImageDownloadService::class)->download('https://yadi.sk/i/invalid', 'vehicle_image', 'uploads/test'))
        ->toThrow(RuntimeException::class);
    expect(Storage::disk('public')->allFiles())->toBe($files);
    Http::assertSentCount(3);
});

test('a second snapshot cannot start while the same source is running or paused', function (): void {
    ImportRun::factory()->create(['type' => 'catalog', 'status' => ImportRunStatus::Paused, 'initialized_at' => now()]);
    $run = completeModernCatalogImport(modernCatalogFile([modernCatalogRow()]));
    expect($run->status)->toBe(ImportRunStatus::Failed)
        ->and($run->last_error)->toContain('Другой импорт')
        ->and(Product::count())->toBe(0);
});

function snapshotFile(array $details, array $rows, ?array $order = null, string $sheetName = 'Любая редакция'): string
{
    $headers = ['Ссылка на фото автомобиля', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', ...$details];
    $order ??= array_keys($headers);
    $path = Storage::disk('local')->path(uniqid('snapshot-').'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName($sheetName);
    $writer->addRow(Row::fromValues(array_fill(0, count($headers), null)));
    $writer->addRow(Row::fromValues(array_map(fn ($i) => $headers[$i], $order)));
    foreach ($rows as $cells) {
        $row = [null, 'Acura', 'Integra', '3', '1993 - 2001', 'Седан 4 дв.', ...$cells];
        $writer->addRow(Row::fromValues(array_map(fn ($i) => $row[$i] ?? null, $order)));
    }
    $writer->addNewSheetAndMakeItCurrent()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues(['must never be imported']));
    $writer->close();

    return $path;
}

function assertSnapshotSuccess(ImportRun $run): void
{
    test()->assertSame(0, $run->errors_count, $run->last_error ?? $run->logs()->get()->toJson(JSON_UNESCAPED_UNICODE));
    expect($run->status)->toBe(ImportRunStatus::Done);
}

test('snapshot archives missing cells and reactivates identical cards with all variants and manual fields', function (): void {
    $details = ['Порог', 'Арка / Задняя', 'Арка / Внутренняя', 'Арка / Внутренняя универсальная', 'Лонжерон', 'Торцевая заглушка', 'Ремкомплект пола', 'Усилитель / соединитель порогов'];
    $path = snapshotFile($details, [array_fill(0, 8, 1)]);
    assertSnapshotSuccess(completeModernCatalogImport($path));
    expect(Product::where('status', ProductStatus::Active)->count())->toBe(8);
    $old = Product::firstOrFail();
    $old->update(['description' => 'Ручное описание', 'slug' => 'my-permanent-url', 'price' => 3500]);
    $before = Product::orderBy('id')->get(['id', 'slug', 'import_key'])->toArray();
    $variants = ProductVariant::orderBy('id')->get()->toArray();
    $manual = Product::factory()->create(['import_source' => null, 'status' => ProductStatus::Active]);
    $foreign = Product::factory()->create(['import_source' => 'another', 'status' => ProductStatus::Active]);
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile($details, [[null, 1, 1, 1, 1, 1, 1, 1]])));
    expect($old->refresh()->status)->toBe(ProductStatus::Archived)
        ->and(Product::where('import_source', 'catalog')->where('status', ProductStatus::Active)->count())->toBe(7)
        ->and($manual->refresh()->status)->toBe(ProductStatus::Active)->and($foreign->refresh()->status)->toBe(ProductStatus::Active);
    assertSnapshotSuccess(completeModernCatalogImport($path));
    assertSnapshotSuccess(completeModernCatalogImport($path));
    expect($old->refresh()->status)->toBe(ProductStatus::Active)->and($old->description)->toBe('Ручное описание')->and($old->price)->toBe('3500.00')
        ->and(Product::where('import_source', 'catalog')->orderBy('id')->get(['id', 'slug', 'import_key'])->toArray())->toBe($before)
        ->and(ProductVariant::whereIn('product_id', array_column($before, 'id'))->orderBy('id')->get()->toArray())->toBe($variants);
});

test('columns can be reordered added removed and structurally renamed without special detail rules', function (): void {
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Новая деталь', 'Другая деталь'], [[1, 1]])));
    $ids = Product::orderBy('id')->pluck('id')->all();
    $first = Product::findOrFail($ids[0]);
    $second = Product::findOrFail($ids[1]);
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['  НОВАЯ  ДЕТАЛЬ ', 'Другая деталь'], [[1, 1]], [7, 3, 1, 2, 0, 6, 5, 4])));
    expect(Product::orderBy('id')->pluck('id')->all())->toBe($ids);
    $first->update(['price' => 4100]);
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Новая деталь', 'Совсем иная'], [[1, 1]])));
    expect($first->refresh()->price)->toBe('4100.00')->and($second->refresh()->status)->toBe(ProductStatus::Archived)->and(Product::count())->toBe(3);
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Новая деталь'], [[1]])));
    expect(Product::where('status', ProductStatus::Active)->count())->toBe(1);
});

test('colliding columns fail before any assortment mutation', function (): void {
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Деталь'], [[1]])));
    $before = Product::firstOrFail()->getAttributes();
    foreach ([snapshotFile(['Деталь', ' деталь '], [[1, 1]])] as $path) {
        $run = completeModernCatalogImport($path);
        expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->processed_rows)->toBe(0)->and($run->errors_count)->toBe(1)
            ->and($run->archived_products)->toBe(0)->and(Product::firstOrFail()->getAttributes())->toBe($before);
    }
});

test('invalid first sheet never falls back to a valid later sheet', function (): void {
    $run = completeModernCatalogImport(modernCatalogFile([modernCatalogRow()], true));
    expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->processed_rows)->toBe(0)->and(Product::count())->toBe(0);
});

test('a failed partially processed snapshot keeps old assortment and a fresh retry reuses staged cards', function (): void {
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Старое'], [[1]])));
    $old = Product::firstOrFail();
    $path = snapshotFile(['Новое'], [[1], [2]]);
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($path), basename($path), chunkSize: 1);
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    app()->call([new CatalogImportChunkJob($run->id), 'handle']);
    $this->assertSame(0, $run->refresh()->errors_count, $run->last_error ?? $run->logs()->get()->toJson(JSON_UNESCAPED_UNICODE));
    expect($old->refresh()->status)->toBe(ProductStatus::Active);
    $staged = Product::whereKeyNot($old->id)->firstOrFail();
    expect($staged->status)->toBe(ProductStatus::Archived);
    app(ImportStatusService::class)->fail($run, 'Simulated worker failure');
    assertSnapshotSuccess(completeModernCatalogImport($path));
    expect($staged->refresh()->status)->toBe(ProductStatus::Active)->and($old->refresh()->status)->toBe(ProductStatus::Archived)->and(Product::count())->toBe(2);
});

test('existing legacy structural identity retains its key even when body typography changes', function (): void {
    $this->seed(PartTypeSeeder::class);
    $generation = app(ImportRowProcessor::class)->vehicleGeneration('Acura', 'Integra', '3', '1993 - 2001', 'Седан 4 двери');
    $run = ImportRun::factory()->create(['type' => 'catalog']);
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    $product = app(ImportProductFactory::class)->createOrUpdateFromCell($run, $generation, $type, $type->productCategory, [], '1');
    $before = $product->only(['id', 'slug', 'import_key']);
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Порог'], [[1]])));
    expect(Product::count())->toBe(1)->and($product->refresh()->only(['id', 'slug', 'import_key']))->toBe($before);
});

test('actual supplied workbook accepts maver as absence during preparation', function (): void {
    $fixture = getenv('CATALOG_IMPORT_NEW_FIXTURE');
    if (! is_string($fixture) || ! is_file($fixture)) {
        $this->markTestSkipped('Set CATALOG_IMPORT_NEW_FIXTURE to the original supplied workbook.');
    }
    $before = hash_file('sha256', $fixture);
    $path = Storage::disk('local')->path('supplied-original.xlsx');
    copy($fixture, $path);
    $reader = app(SpreadsheetReader::class);
    $prepared = $reader->prepareRows($path);
    $row = collect($reader->readPreparedChunk($prepared, 0, count($prepared['offsets'])))
        ->firstWhere('_row_number', 1326);
    expect($row['_details'][12])->toBe('maver')
        ->and(CatalogImportCell::parse($row['_details'][12]))->toBeNull()
        ->and(count($prepared['offsets']))->toBe($reader->countRows($path, 2))
        ->and(hash_file('sha256', $fixture))->toBe($before);
});

test('full supplied workbook snapshot synchronizes a legacy catalog and repeats without duplicate cards or variants', function (): void {
    $fixture = getenv('CATALOG_IMPORT_NEW_FIXTURE');
    if (! is_string($fixture) || ! is_file($fixture)) {
        $this->markTestSkipped('Set CATALOG_IMPORT_NEW_FIXTURE to the original supplied workbook.');
    }
    $sourceHash = hash_file('sha256', $fixture);
    // Independent expected inventory from the actual fixture cells, before using the importer.
    $sourceOptions = new OpenSpout\Reader\XLSX\Options;
    $sourceOptions->SHOULD_FORMAT_DATES = true;
    $source = new Reader($sourceOptions);
    $source->open($fixture);
    $expected = [];
    $rowCount = 0;
    $integraCount = 0;
    foreach ($source->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $number => $sourceRow) {
            if ($number <= 2) {
                continue;
            }
            $cells = $sourceRow->toArray();
            if (count(array_filter($cells, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $rowCount++;
            if ($number === 1326) {
                expect($cells[12])->toBe('maver');
            }
            foreach (range(6, 18) as $column) {
                $value = str_replace(',', '.', trim((string) ($cells[$column] ?? '')));
                if (! is_numeric($value) || (float) $value !== 1.0) {
                    continue;
                }
                $car = array_map(fn ($value) => mb_strtolower(CatalogText::plain($value)), array_slice($cells, 1, 5));
                $expected[json_encode([$car, $column])] = true;
                if ($rowCount === 1) {
                    $integraCount++;
                }
            }
        }
        break;
    }
    $source->close();
    expect($integraCount)->toBe(8);
    $root = sys_get_temp_dir().'/dvashop-full-snapshot-'.getmypid();
    mkdir($root, 0777, true);
    config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $root]]);
    Storage::forgetDisk('local');
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Порог', 'Арка / Задняя', 'Арка / Внутренняя', 'Арка / Внутренняя универсальная', 'Лонжерон', 'Торцевая заглушка', 'Ремкомплект пола', 'Усилитель / соединитель порогов'], [array_fill(0, 8, 1)])));
    $legacy = Product::orderBy('id')->get(['id', 'slug', 'import_key'])->toArray();
    $obsolete = Product::factory()->create(['import_source' => 'catalog', 'import_key' => 'catalog:obsolete', 'status' => ProductStatus::Active]);
    $path = Storage::disk('local')->path('full.xlsx');
    copy($fixture, $path);
    $first = completeModernCatalogImport($path, 100);
    $this->assertSame(0, $first->errors_count, $first->last_error ?? $first->logs()->where('level', 'error')->get()->toJson(JSON_UNESCAPED_UNICODE));
    expect($first->processed_rows)->toBe($rowCount)->and($first->archive_skipped)->toBeFalse()
        ->and(Product::where('status', ProductStatus::Active)->count())->toBe(count($expected))
        ->and($obsolete->refresh()->status)->toBe(ProductStatus::Archived)
        ->and(Product::whereKey(array_column($legacy, 'id'))->orderBy('id')->get(['id', 'slug', 'import_key'])->toArray())->toBe($legacy)
        ->and(Product::whereKey(array_column($legacy, 'id'))->where('status', ProductStatus::Active)->count())->toBe(8);
    $first->forceFill(['processed_images' => $first->queued_images])->save();
    app(ImportStatusService::class)->markRowsDone($first);
    $before = Product::orderBy('id')->get(['id', 'slug', 'import_key'])->toArray();
    $variants = ProductVariant::count();
    $second = completeModernCatalogImport($path, 100);
    expect($second->errors_count)->toBe(0)->and($second->processed_rows)->toBe($rowCount)
        ->and($second->created_products)->toBe(0)->and($second->created_generations)->toBe(0)
        ->and(Product::orderBy('id')->get(['id', 'slug', 'import_key'])->toArray())->toBe($before)
        ->and(ProductVariant::count())->toBe($variants)
        ->and(Product::where('status', ProductStatus::Active)->count())->toBe(count($expected));
    expect(hash_file('sha256', $fixture))->toBe($sourceHash);
    fwrite(STDOUT, "\nWorkbook rows={$rowCount}; active products=".count($expected)."; variants={$variants}; legacy Acura cards=8; repeat=OK\n");
});

test('a changed imported photo url with identical content is remembered without touching manual photos', function (): void {
    Storage::fake('public');
    $product = Product::factory()->create();
    Http::fake(['images.example.test/*' => Http::response(test_image_binary(), 200, ['Content-Type' => 'image/jpeg'])]);
    $downloader = app(ImportImageDownloader::class);
    $first = $downloader->download($product, 'https://images.example.test/old.jpg');
    $manual = ProductImage::factory()->for($product)->create(['source_type' => 'manual', 'is_main' => true]);
    $before = $manual->fresh()->getAttributes();
    $changed = $downloader->download($product, 'https://images.example.test/new.jpg');
    $repeat = $downloader->download($product, 'https://images.example.test/new.jpg');
    expect($changed->id)->toBe($first->id)->and($repeat->id)->toBe($first->id)
        ->and($first->refresh()->source_url)->toBe('https://images.example.test/new.jpg')
        ->and($manual->refresh()->getAttributes())->toBe($before)->and($product->images()->count())->toBe(2);
    Http::assertSentCount(2);
});

test('changed vehicle photo is downloaded before its source is replaced and identical repeat is skipped', function (): void {
    Storage::fake('public');
    Http::fake([
        'images.example.test/old.jpg' => Http::response(test_image_binary('jpeg', 32, 24), 200, ['Content-Type' => 'image/jpeg']),
        'images.example.test/new.jpg' => Http::response(test_image_binary('jpeg', 40, 30), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $processor = app(ImportRowProcessor::class);
    $generation = $processor->vehicleGeneration('Example', 'Car', 'I', '2000-2005', 'Седан');
    $downloader = app(ImportImageDownloader::class);
    $downloader->downloadVehicleGenerationImage($generation, 'https://images.example.test/old.jpg');
    $oldPath = $generation->refresh()->image;
    $processor->vehicleGeneration('Example', 'Car', 'I', '2000-2005', 'Седан', 'https://images.example.test/new.jpg');
    expect($generation->refresh()->image_source_url)->toBe('https://images.example.test/old.jpg');
    $downloader->downloadVehicleGenerationImage($generation, 'https://images.example.test/new.jpg');
    expect($generation->refresh()->image_source_url)->toBe('https://images.example.test/new.jpg')->and($generation->image)->not->toBe($oldPath);
    $downloader->downloadVehicleGenerationImage($generation, 'https://images.example.test/new.jpg');
    Http::assertSentCount(2);
});

test('snapshot schema migration preserves existing products variants and mappings', function (): void {
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Порог'], [[1]])));
    $migration = require database_path('migrations/2026_10_09_000100_create_catalog_import_snapshot_items.php');
    $migration->down();
    $before = Product::firstOrFail()->getAttributes();
    $variants = ProductVariant::orderBy('id')->get()->toArray();
    $mappings = ProductVariantOptionValue::orderBy('id')->get()->toArray();
    $migration->up();
    $after = Product::firstOrFail()->getAttributes();
    unset($after['last_import_price']);
    expect($after)->toBe($before)
        ->and(ProductVariant::orderBy('id')->get()->toArray())->toBe($variants)
        ->and(ProductVariantOptionValue::orderBy('id')->get()->toArray())->toBe($mappings);
});

test('search refresh is queued only after snapshot publication', function (): void {
    config(['scout.driver' => 'meilisearch']);
    $path = snapshotFile(['Деталь'], [[1], [1]]);
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($path), basename($path), chunkSize: 1);
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    app()->call([new CatalogImportChunkJob($run->id), 'handle']);
    Queue::assertNotPushed(PublishCatalogSearch::class);
    expect(Product::where('status', ProductStatus::Active)->count())->toBe(0);
    app()->call([new CatalogImportChunkJob($run->id), 'handle']);
    assertSnapshotSuccess($run->refresh());
    Queue::assertPushed(PublishCatalogSearch::class);
});

test('a snapshot cannot claim an existing key owned by another source', function (): void {
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Деталь'], [[1]])));
    $product = Product::firstOrFail();
    $product->update(['import_source' => 'manual']);
    $before = $product->fresh()->getAttributes();
    $run = completeModernCatalogImport(snapshotFile(['Деталь'], [[1]]));
    expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->last_error)->toContain('другого источника')
        ->and($product->fresh()->getAttributes())->toBe($before);
});

test('empty snapshots and unnamed populated columns are rejected', function (): void {
    foreach ([snapshotFile(['Деталь'], [[null]]), snapshotFile([''], [[1]])] as $path) {
        $run = completeModernCatalogImport($path);
        expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->processed_rows)->toBe(0)->and(Product::count())->toBe(0);
    }
});

test('platform and restyling distinguish vehicles and omitted generations are deactivated', function (): void {
    $path = modernCatalogFile([
        modernCatalogRow([15 => 'Platform A', 16 => 'Before']),
        modernCatalogRow([15 => 'Platform B', 16 => 'Before']),
        modernCatalogRow([15 => 'Platform B', 16 => 'After']),
    ]);
    assertSnapshotSuccess(completeModernCatalogImport($path));
    expect(VehicleGeneration::count())->toBe(3)->and(Product::count())->toBe(3);
    $manual = VehicleGeneration::factory()->create();
    assertSnapshotSuccess(completeModernCatalogImport(modernCatalogFile([modernCatalogRow([15 => 'Platform B', 16 => 'After'])])));
    expect(Product::where('status', ProductStatus::Active)->count())->toBe(1)
        ->and(VehicleGeneration::where('import_source', 'catalog')->where('is_active', true)->count())->toBe(1)
        ->and($manual->refresh()->is_active)->toBeTrue();
    assertSnapshotSuccess(completeModernCatalogImport($path));
    expect(Product::count())->toBe(3)->and(Product::where('status', ProductStatus::Active)->count())->toBe(3);
});

test('retry after the last row checkpoint still validates and publishes the snapshot', function (bool $rowError): void {
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile(['Прежняя деталь'], [[1]])));
    $previous = Product::firstOrFail();
    $path = snapshotFile(['Новая деталь'], [[1]]);
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($path), basename($path));
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    $run->refresh();
    $row = app(SpreadsheetReader::class)->readPreparedChunk($run->prepared_rows, 0, 1)[0];
    app(ImportRowProcessor::class)->process($run, $row, $run->detail_columns, $row['_row_number']);
    $staged = Product::whereKeyNot($previous->id)->firstOrFail();
    expect($staged->status)->toBe(ProductStatus::Archived);

    // Simulate worker loss between the last checkpoint commit and final reconciliation.
    app(ImportStatusService::class)->updateRowsProgress($run, $run->total_rows);
    $run->forceFill(['errors_count' => $rowError ? 1 : 0])->save();
    app()->call([new CatalogImportChunkJob($run->id), 'handle']);

    expect($run->refresh()->status)->toBe($rowError ? ImportRunStatus::Failed : ImportRunStatus::Done)
        ->and($previous->refresh()->status)->toBe($rowError ? ProductStatus::Active : ProductStatus::Archived)
        ->and($staged->refresh()->status)->toBe($rowError ? ProductStatus::Archived : ProductStatus::Active)
        ->and(Product::count())->toBe(2);
})->with([false, true]);

test('absent values archive a card and one restores its identity and variants without duplicates', function (mixed $value): void {
    $headers = ['Порог', 'Контрольная деталь'];
    assertSnapshotSuccess(completeModernCatalogImport(snapshotFile($headers, [[1, 1]])));
    $product = Product::whereHas('partType', fn ($q) => $q->where('full_slug', 'porog'))->firstOrFail();
    $identity = $product->only(['id', 'slug', 'import_key']);
    $variants = $product->variants()->orderBy('id')->get()->toArray();
    $path = snapshotFile($headers, [[$value, 1]]);
    $absent = completeModernCatalogImport($path);
    assertSnapshotSuccess($absent);
    expect($absent->prepared_rows['expected_products'])->toBe(1)
        ->and($product->refresh()->status)->toBe(ProductStatus::Archived)
        ->and(Product::count())->toBe(2);
    assertSnapshotSuccess(completeModernCatalogImport($path));
    $presentPath = snapshotFile($headers, [[' 1.0 ', 1]]);
    assertSnapshotSuccess(completeModernCatalogImport($presentPath));
    assertSnapshotSuccess(completeModernCatalogImport($presentPath));
    expect($product->refresh()->status)->toBe(ProductStatus::Active)
        ->and($product->only(['id', 'slug', 'import_key']))->toBe($identity)
        ->and($product->variants()->orderBy('id')->get()->toArray())->toBe($variants)
        ->and(Product::count())->toBe(2);
})->with([0, '0', null, '', '   ', 'maver', 'abc', 'да', 2, -1, 1.5, true, 'https://example.test/image.jpg']);

test('database base prices survive snapshot reactivation repeats and apply to new cars with 24 variants', function (): void {
    $this->seed(PartTypeSeeder::class);
    $first = modernCatalogFile([modernCatalogRow(), modernCatalogRow([2 => 'TSX'])]);
    assertSnapshotSuccess(completeModernCatalogImport($first));
    $p = Product::where('title', 'like', '%MDX%')->firstOrFail();
    $manual = Product::where('title', 'like', '%TSX%')->firstOrFail();
    $manual->update(['price' => 9876]);
    $ids = $p->variants()->orderBy('id')->pluck('id')->all();
    $slug = $p->slug;
    assertSnapshotSuccess(completeModernCatalogImport(modernCatalogFile([modernCatalogRow([2 => 'TSX'])])));
    expect($p->refresh()->status)->toBe(ProductStatus::Archived);
    $prices = app(CatalogBulkPrices::class);
    $admin = User::factory()->superAdmin()->create();
    $operation = $prices->confirm($admin, $prices->preview($p->part_type_id, false, '3123.45'));
    while (! $prices->batch($operation)) {
    }
    $next = modernCatalogFile([modernCatalogRow(), modernCatalogRow([2 => 'TSX']), modernCatalogRow([2 => 'RL'])]);
    assertSnapshotSuccess(completeModernCatalogImport($next));
    assertSnapshotSuccess(completeModernCatalogImport($next));
    expect($p->refresh()->price)->toBe('3123.45')->and($p->price_mode)->toBe('inherited')
        ->and($p->status)->toBe(ProductStatus::Active)->and($p->slug)->toBe($slug)
        ->and($p->variants()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($p->variants()->where('price', '3123.45')->count())->toBe(24)
        ->and($manual->refresh()->price)->toBe('9876.00')->and($manual->price_mode)->toBe('individual');
    $new = Product::where('title', 'like', '% RL %')->firstOrFail();
    expect($new->price)->toBe('3123.45')->and($new->variants()->where('price', '3123.45')->count())->toBe(24)
        ->and(Product::count())->toBe(3);
});
