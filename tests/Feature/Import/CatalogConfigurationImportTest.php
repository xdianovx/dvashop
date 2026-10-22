<?php

use App\Enums\ImportRunStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Import\CatalogImportIdentity;
use App\Services\Import\ImportRowProcessor;
use App\Services\ImportStatusService;
use App\Services\SpreadsheetReader;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Requires isolated in-memory SQLite.');
    }
    $this->artisan('migrate', ['--force' => true]);
    Queue::fake();
    Storage::fake('local');
    Storage::fake('public');
    Http::preventStrayRequests();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    app(CatalogConfigurations::class)->install(base_path('tests/Fixtures/catalog-configurations.csv'));
});

function configurationWorkbook(array $headers, array $rows): string
{
    $path = Storage::disk('local')->path(uniqid('configuration-').'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues(array_fill(0, 6 + count($headers), null)));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', ...$headers]));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues([null, 'Acura', 'Integra', '3', '1993 - 2001', 'Седан 4 дв.', ...$row]));
    }
    $writer->close();

    return $path;
}

function importConfigurationWorkbook(string $path): ImportRun
{
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($path), basename($path), chunkSize: 100);
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    while ($run->refresh()->status === ImportRunStatus::RunningRows) {
        app()->call([new CatalogImportChunkJob($run->id), 'handle']);
        if ($run->total_rows > 100) {
            fwrite(STDOUT, 'ROWS '.$run->fresh()->processed_rows.'/'.$run->total_rows.PHP_EOL);
        }
    }
    test()->assertSame(0, $run->refresh()->errors_count, $run->last_error ?? $run->logs()->get()->toJson(JSON_UNESCAPED_UNICODE));
    expect($run->status)->toBeIn([ImportRunStatus::Done, ImportRunStatus::ProcessingImages]);
    // Image download is independent and not needed to finish fixture snapshot authority.
    $run->forceFill(['status' => ImportRunStatus::Done])->save();

    return $run;
}

test('separate headers archive legacy identity combine duplicates by OR and preserve returning IDs', function () {
    // An imported legacy type from a previous format is historical, not a CSV configuration.
    $legacyType = PartType::where('full_slug', 'usilitel/soedinitel-porogov')->firstOrFail();
    $legacy = Product::factory()->forPartType($legacyType)->create([
        'import_source' => 'catalog', 'import_key' => 'catalog:legacy-coupler', 'status' => 'active',
    ]);
    importConfigurationWorkbook(configurationWorkbook(['Порог'], [[1]]));
    $sill = Product::whereHas('partType', fn ($q) => $q->where('full_slug', 'porog'))->firstOrFail();
    $legacyState = $legacy->only(['id', 'slug', 'import_key']);
    $sillState = $sill->only(['id', 'slug', 'import_key']);
    $path = configurationWorkbook(['Соединитель порогов', 'Усилитель  порогов', 'Порог'], [[1, 0, 1], [0, 1, 0], ['maver', 'abc', 0]]);
    importConfigurationWorkbook($path);
    expect($legacy->refresh()->status->value)->toBe('archived')->and($legacy->only(['id', 'slug', 'import_key']))->toBe($legacyState)
        ->and($sill->refresh()->only(['id', 'slug', 'import_key']))->toBe($sillState);
    foreach (['usilitel', 'soedinitel'] as $slug) {
        $p = Product::whereHas('partType', fn ($q) => $q->where('full_slug', $slug))->firstOrFail();
        expect($p->status->value)->toBe('active')->and($p->variants()->where('is_active', true)->count())->toBe(12);
    }
    $ids = Product::orderBy('id')->pluck('slug', 'id')->all();
    $variants = ProductVariant::count();
    importConfigurationWorkbook(configurationWorkbook(['Усилитель порогов', 'Соединитель порогов', 'Порог'], [[1, 0, 0], [0, 1, 1]]));
    expect(Product::orderBy('id')->pluck('slug', 'id')->all())->toBe($ids)->and(ProductVariant::count())->toBe($variants);
    // Old, now unsupported headers must not reactivate a retired legacy card.
    $invalid = configurationWorkbook(['Усилитель / соединитель порогов'], [[1]]);
    $run = app(ImportStatusService::class)->createFromStoredPath('catalog', basename($invalid), basename($invalid), chunkSize: 100);
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    expect($run->refresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($legacy->refresh()->status->value)->toBe('archived')
        ->and($legacy->only(['id', 'slug', 'import_key']))->toBe($legacyState);
});

test('current supplied Excel first sheet imports and repeats the entire matrix without duplicates', function () {
    $source = getenv('CATALOG_CONFIGURATION_XLSX');
    if (! $source || ! is_file($source)) {
        $this->markTestSkipped('Set CATALOG_CONFIGURATION_XLSX to the current 2080-row workbook.');
    }
    $path = Storage::disk('local')->path('actual-current.xlsx');
    copy($source, $path);
    $reader = app(SpreadsheetReader::class);
    $prepared = $reader->prepareRows($path);
    expect($prepared['sheet'])->toBe('Каталог (new)')->and($prepared['offsets'])->toHaveCount(2080);
    $run = ImportRun::factory()->create(['status' => ImportRunStatus::Done]);
    $columns = app(ImportRowProcessor::class)->prepareDetailColumns($run, $reader->readMergedDetailHeaders($path));
    $expected = [];
    $rawIdentities = [];
    $identity = app(CatalogImportIdentity::class);
    foreach ($reader->readPreparedChunk($prepared, 0, 2080) as $row) {
        $rawIdentities[implode('|', array_slice($row, 1, 5))] = true;
        foreach ($columns as $col => $header) {
            if (trim((string) ($row['_details'][$col] ?? '')) !== '1') {
                continue;
            }
            $expected[$identity->key($row, $header['part_type_full_slug'], 'catalog')] = true;
        }
    }
    // An imported legacy type from a previous format is historical, not a CSV configuration.
    $legacyType = PartType::where('full_slug', 'usilitel/soedinitel-porogov')->firstOrFail();
    $legacy = Product::factory()->forPartType($legacyType)->create([
        'import_source' => 'catalog', 'import_key' => 'catalog:legacy-coupler', 'status' => 'active',
    ]);
    importConfigurationWorkbook(configurationWorkbook(['Порог'], [[1]]));
    $sill = Product::whereHas('partType', fn ($q) => $q->where('full_slug', 'porog'))->firstOrFail();
    $old = $sill->only(['id', 'slug']);
    $run = importConfigurationWorkbook($path);
    expect(Product::where('status', 'active')->count())->toBe(count($expected))->and($legacy->refresh()->status->value)->toBe('archived')
        ->and($sill->refresh()->only(['id', 'slug']))->toBe($old);
    $ids = Product::orderBy('id')->pluck('slug', 'id')->all();
    $variantCount = ProductVariant::count();
    fwrite(STDOUT, "\nCURRENT XLSX FIRST: ".json_encode(['expected_active' => count($expected), 'products' => Product::count(), 'variants' => $variantCount, 'created' => $run->created_products, 'archived' => $run->archived_products], JSON_UNESCAPED_UNICODE)."\n");
    $repeat = importConfigurationWorkbook($path);
    expect(Product::orderBy('id')->pluck('slug', 'id')->all())->toBe($ids)->and(ProductVariant::count())->toBe($variantCount);
    fwrite(STDOUT, "\nCURRENT XLSX REPEAT: ".json_encode(['products' => Product::count(), 'variants' => ProductVariant::count(), 'created' => $repeat->created_products, 'archived' => $repeat->archived_products], JSON_UNESCAPED_UNICODE)."\n");
});

test('manual archive survives subsequent Excel publication and ordinary activation removes the hold', function () {
    $path = configurationWorkbook(['Усилитель порогов'], [[1]]);
    importConfigurationWorkbook($path);
    $product = Product::firstOrFail();
    $product->forceFill(['status' => 'archived', 'publication_override' => 'archived'])->save();
    importConfigurationWorkbook($path);
    expect($product->refresh()->status->value)->toBe('archived')
        ->and($product->publication_override)->toBe('archived')
        ->and((bool) $product->import_present)->toBeTrue();
    // Clearing a manual hold is an explicit administrative action, not an Excel side effect.
    $product->forceFill(['publication_override' => null])->save();
    importConfigurationWorkbook($path);
    expect($product->refresh()->status->value)->toBe('active');
    $ids = $product->variants()->pluck('id')->all();
    importConfigurationWorkbook(configurationWorkbook(['Усилитель порогов', 'Соединитель порогов'], [['maver', 1]]));
    expect($product->refresh()->status->value)->toBe('archived');
    importConfigurationWorkbook($path);
    expect($product->refresh()->status->value)->toBe('active')->and($product->variants()->pluck('id')->all())->toBe($ids);
});

test('manual archive and draft remain in force when the product disappears and returns in Excel', function (string $override) {
    $present = configurationWorkbook(['Усилитель порогов'], [[1]]);
    importConfigurationWorkbook($present);
    $product = Product::firstOrFail();
    $id = $product->id;
    $variantIds = $product->variants()->orderBy('id')->pluck('id')->all();
    $product->forceFill(['status' => $override, 'publication_override' => $override])->save();

    importConfigurationWorkbook($present);
    expect($product->refresh()->status->value)->toBe($override)
        ->and($product->publication_override)->toBe($override)
        ->and((bool) $product->import_present)->toBeTrue();

    $missing = configurationWorkbook(['Усилитель порогов', 'Соединитель порогов'], [['maver', 1]]);
    importConfigurationWorkbook($missing);
    expect($product->refresh()->status->value)->toBe($override)
        ->and($product->publication_override)->toBe($override)
        ->and((bool) $product->import_present)->toBeFalse();

    importConfigurationWorkbook($present);
    expect($product->refresh()->id)->toBe($id)
        ->and($product->status->value)->toBe($override)
        ->and($product->publication_override)->toBe($override)
        ->and((bool) $product->import_present)->toBeTrue()
        ->and($product->variants()->orderBy('id')->pluck('id')->all())->toBe($variantIds);
})->with(['archived', 'draft']);

test('repeat Excel does not create a technical replacement for deliberately inactive configured variants', function () {
    $path = configurationWorkbook(['Усилитель порогов'], [[1]]);
    importConfigurationWorkbook($path);
    $product = Product::firstOrFail();
    $ids = $product->variants()->orderBy('id')->pluck('id')->all();
    DB::table('product_variants')->where('product_id', $product->id)->update(['is_active' => false, 'is_default' => false]);
    importConfigurationWorkbook($path);
    expect($product->variants()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($product->variants()->where('is_active', true)->exists())->toBeFalse();
});
