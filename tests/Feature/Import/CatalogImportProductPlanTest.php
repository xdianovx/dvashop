<?php

use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Import\CatalogImportIdentity;
use App\Services\Import\CatalogImportProductPlan;
use App\Services\Import\CatalogImportSnapshot;
use App\Services\Import\ImportPartTypeResolver;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed([ProductCatalogSeeder::class, PartTypeSeeder::class]);
    $this->planner = app(CatalogImportProductPlan::class);
});

function productPlanWorkbook(array $rows, array $details = ['Усилитель']): string
{
    $path = Storage::disk('local')->path(uniqid('product-plan-').'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues(array_fill(0, 6 + count($details), null)));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', ...$details]));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

function productPlanLegacyPhotoWorkbook(string $leadingCell): string
{
    $path = Storage::disk('local')->path(uniqid('product-plan-legacy-photo-').'.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues(array_fill(0, 7, null)));
    $writer->addRow(Row::fromValues([null, 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Усилитель']));
    $writer->addRow(Row::fromValues([$leadingCell, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1]));
    $writer->close();

    return $path;
}

function productPlanRow(array $details = [1], string $model = 'MDX'): array
{
    return [null, 'Acura', $model, '2', '2006–2013', 'Внедорожник', ...$details];
}

function productPlanKey(string $model = 'MDX', string $part = 'usilitel', string $source = 'catalog'): string
{
    return CatalogImportIdentity::fromSegments($source, 'acura', CatalogText::normKey($model, 'model', 120),
        CatalogText::normKey('2 2006–2013 Внедорожник', 'generation', 120), $part);
}

function productPlanExisting(array $attributes = []): Product
{
    return Product::factory()->create(array_replace([
        'part_type_id' => PartType::where('full_slug', 'usilitel')->value('id'),
        'import_source' => 'catalog', 'import_key' => productPlanKey(),
        'status' => 'active', 'price_mode' => 'unknown', 'slug' => 'historical-product-url',
    ], $attributes));
}

function productPlanDatabaseState(): array
{
    $tables = ['products', 'product_variants', 'vehicle_makes', 'vehicle_models', 'vehicle_generations',
        'product_categories', 'part_types', 'product_fitments', 'catalog_import_snapshot_items', 'import_runs', 'import_logs'];

    return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
}

test('a new product and unknown detail type are planned without creating any entities', function () {
    $path = productPlanWorkbook([productPlanRow()], ['Новая деталь']);
    $before = productPlanDatabaseState();
    $plan = $this->planner->plan($path);
    $pathFromHeader = app(ImportPartTypeResolver::class)->structuralPath(['title' => 'Новая деталь']);
    expect($plan['counts'])->toMatchArray(['expected' => 1, 'new' => 1, 'existing' => 0, 'archive' => 0])
        ->and($plan['expected_products'][0])->toMatchArray([
            'product_id' => null, 'slug' => null, 'state' => 'new', 'action' => 'create',
            'import_key' => productPlanKey(part: $pathFromHeader), 'part_type_full_slug' => $pathFromHeader,
            'source_rows' => [3],
        ])
        ->and(productPlanDatabaseState())->toBe($before);
});

test('an existing product keeps its database identity and slug in the plan', function () {
    $product = productPlanExisting();
    $plan = $this->planner->plan(productPlanWorkbook([productPlanRow()]));
    expect($plan['counts'])->toMatchArray(['new' => 0, 'existing' => 1, 'reactivate' => 0])
        ->and($plan['expected_products'][0])->toMatchArray([
            'product_id' => $product->id, 'slug' => $product->slug, 'import_key' => $product->import_key,
            'state' => 'existing', 'action' => 'keep',
        ]);
});

test('archived and soft deleted matches are separate reactivation candidates without changing their state', function (bool $deleted) {
    $product = productPlanExisting(['status' => 'archived']);
    if ($deleted) {
        DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]);
    }
    $before = productPlanDatabaseState();
    $plan = $this->planner->plan(productPlanWorkbook([productPlanRow()]));
    expect($plan['counts'])->toMatchArray(['new' => 0, 'existing' => 1, 'archived' => 1, 'reactivate' => 1,
        'restore_deleted' => $deleted ? 1 : 0])
        ->and($plan['expected_products'][0])->toMatchArray(['product_id' => $product->id,
            'state' => $deleted ? 'deleted' : 'archived', 'action' => 'reactivate', 'restore_deleted' => $deleted])
        ->and(productPlanDatabaseState())->toBe($before);
})->with([false, true]);

test('disappeared products are classified only within the source and already archived ones stay distinct', function () {
    $active = productPlanExisting();
    $archived = productPlanExisting(['import_key' => productPlanKey('RDX'), 'slug' => 'already-archived', 'status' => 'archived']);
    productPlanExisting(['import_source' => 'other', 'import_key' => 'other:key', 'slug' => 'other-source']);
    productPlanExisting(['import_key' => null, 'slug' => 'without-import-key']);
    $deleted = productPlanExisting(['import_key' => productPlanKey('NSX'), 'slug' => 'deleted-and-absent']);
    DB::table('products')->where('id', $deleted->id)->update(['deleted_at' => now()]);
    $before = productPlanDatabaseState();
    $plan = $this->planner->plan(productPlanWorkbook([productPlanRow(model: 'Integra')]));
    expect($plan['counts'])->toMatchArray(['expected' => 1, 'new' => 1, 'missing' => 2, 'archive' => 1, 'already_archived' => 1])
        ->and(collect($plan['missing_products'])->firstWhere('product_id', $active->id)['action'])->toBe('archive')
        ->and(collect($plan['missing_products'])->firstWhere('product_id', $archived->id)['action'])->toBe('keep_archived')
        ->and(productPlanDatabaseState())->toBe($before);
});

test('duplicate rows merge each detail by OR independently of row order and do not duplicate keys', function () {
    $rows = [productPlanRow([1, 0]), productPlanRow([0, true]), productPlanRow([' TrUe ', 'maver'])];
    $plan = $this->planner->plan(productPlanWorkbook($rows, ['Усилитель', 'Соединитель порогов']));
    $reverse = $this->planner->plan(productPlanWorkbook(array_reverse($rows), ['Усилитель', 'Соединитель порогов']));
    expect($plan['counts'])->toMatchArray(['expected' => 2, 'new' => 2])
        ->and($plan['diagnostics'])->toMatchArray(['accepted_rows' => 3, 'duplicate_vehicle_rows' => 2, 'duplicate_positive_cells' => 1])
        ->and(collect($plan['expected_products'])->pluck('import_key')->sort()->values()->all())
        ->toBe(collect($reverse['expected_products'])->pluck('import_key')->sort()->values()->all())
        ->and(collect($plan['expected_products'])->firstWhere('part_type_full_slug', 'usilitel')['source_rows'])->toBe([3, 5])
        ->and(collect($plan['expected_products'])->firstWhere('part_type_full_slug', 'soedinitel')['source_rows'])->toBe([4]);
});

test('a conflicting key owned by a manual or other source product is rejected including deleted owners', function (?string $owner, bool $deleted) {
    $product = productPlanExisting(['import_source' => $owner]);
    if ($deleted) {
        DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]);
    }
    $before = productPlanDatabaseState();
    $path = productPlanWorkbook([productPlanRow()]);
    expect(fn () => $this->planner->plan($path))->toThrow(RuntimeException::class, 'другого источника')
        ->and(productPlanDatabaseState())->toBe($before);
})->with([[null, false], ['other', false], ['other', true]]);

test('planning neither writes to the database nor prepares files or dispatches jobs', function () {
    productPlanExisting();
    $manual = Product::factory()->create(['import_source' => null, 'price_mode' => 'unknown']);
    ProductVariant::factory()->create(['product_id' => $manual->id, 'price' => '777.00', 'price_mode' => 'unknown']);
    $path = productPlanWorkbook([productPlanRow()]);
    $before = productPlanDatabaseState();
    $files = Storage::disk('local')->allFiles();
    $sha256 = hash_file('sha256', $path);
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $plan = $this->planner->plan($path);
    expect($plan['purpose'])->toBe('excel_assortment_only')
        ->and($writes)->toBe([])->and(productPlanDatabaseState())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toBe($files)
        ->and(hash_file('sha256', $path))->toBe($sha256);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('the actual snapshot reuses the exact planned keys and OR counters', function () {
    $path = productPlanWorkbook([productPlanRow([1, 0]), productPlanRow([true, 1])], ['Усилитель', 'Соединитель порогов']);
    $plan = $this->planner->plan($path);
    $reader = app(SpreadsheetReader::class);
    $prepared = $reader->prepareRows($path);
    $headers = $reader->readMergedDetailHeaders($path);
    foreach ($headers as &$header) {
        $header['part_type_full_slug'] = app(ImportPartTypeResolver::class)->structuralPath($header);
    }
    unset($header);
    $run = ImportRun::factory()->create(['type' => 'catalog']);
    $before = Product::count();
    $count = app(CatalogImportSnapshot::class)->prepare($run, $prepared, $headers, $reader);
    $keys = DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->orderBy('import_key')->pluck('import_key')->all();
    expect($count)->toBe($plan['counts']['expected'])
        ->and($keys)->toBe(collect($plan['expected_products'])->pluck('import_key')->sort()->values()->all())
        ->and(Product::count())->toBe($before);
    $log = $run->logs()->where('message', 'Повторные отметки наличия объединены по правилу OR')->firstOrFail();
    expect($log->context['duplicate_vehicle_rows'])->toBe($plan['diagnostics']['duplicate_vehicle_rows'])
        ->and($log->context['duplicate_positive_cells'])->toBe($plan['diagnostics']['duplicate_positive_cells']);
});

test('incomplete rows use reader skip rules and photo is optional', function () {
    $incomplete = productPlanRow();
    $incomplete[3] = '';
    $plan = $this->planner->plan(productPlanWorkbook([$incomplete, productPlanRow()]));
    expect($plan['counts']['expected'])->toBe(1)->and($plan['diagnostics']['accepted_rows'])->toBe(1)
        ->and($plan['diagnostics']['skipped_rows'][0]['row'])->toBe(3)
        ->and($plan['expected_products'][0]['source_rows'])->toBe([4]);
});

test('existing normalized vehicle identity preserves a historical generation key', function () {
    $make = VehicleMake::factory()->create(['title' => 'Acura', 'norm_key' => 'acura']);
    $model = VehicleModel::factory()->create(['vehicle_make_id' => $make->id, 'title' => 'MDX', 'norm_key' => 'mdx']);
    $generation = VehicleGeneration::factory()->create(['vehicle_model_id' => $model->id, 'title' => '2',
        'years_label' => '2006–2013', 'body' => 'Внедорожник']);
    DB::table('vehicle_generations')->where('id', $generation->id)->update(['norm_key' => 'legacy-generation-88']);
    $key = CatalogImportIdentity::fromSegments('catalog', 'acura', 'mdx', 'legacy-generation-88', 'usilitel');
    $product = productPlanExisting(['import_key' => $key]);
    $plan = $this->planner->plan(productPlanWorkbook([productPlanRow()]));
    expect($plan['expected_products'][0]['import_key'])->toBe($key)
        ->and($plan['expected_products'][0]['product_id'])->toBe($product->id)->and($plan['counts']['new'])->toBe(0);
});

test('an empty assortment is rejected rather than proposing to archive everything', function () {
    productPlanExisting();
    $before = productPlanDatabaseState();
    $path = productPlanWorkbook([productPlanRow(['maver'])]);
    expect(fn () => $this->planner->plan($path))->toThrow(RuntimeException::class, 'Пустой снимок')
        ->and(productPlanDatabaseState())->toBe($before);
});

test('manual hiding stays explicit and is not reported as automatic reactivation', function () {
    productPlanExisting(['status' => 'archived', 'publication_override' => 'archived']);
    productPlanExisting(['import_key' => productPlanKey('RDX'), 'slug' => 'manual-draft', 'status' => 'draft', 'publication_override' => 'draft']);
    $plan = $this->planner->plan(productPlanWorkbook([productPlanRow()]));
    expect($plan['counts'])->toMatchArray(['archived' => 1, 'reactivate' => 0, 'archive' => 0, 'preserve_manual' => 2])
        ->and($plan['expected_products'][0]['action'])->toBe('preserve_manual')
        ->and($plan['missing_products'][0]['action'])->toBe('preserve_manual');
});


test('legacy Excel accepts an unlabelled leading photo URL and retains it without database changes', function () {
    $url = 'https://yadi.sk/i/WsZ6nLP-oHzMmQ';
    $path = productPlanLegacyPhotoWorkbook($url);
    $before = productPlanDatabaseState();
    $entries = iterator_to_array(app(SpreadsheetReader::class)->catalogRows($path), false);
    $plan = $this->planner->plan($path);

    expect($entries[0]['row']['_photo_urls'])->toBe([$url])
        ->and($entries[0]['row'][0])->toBe($url)
        ->and($plan['counts']['expected'])->toBe(1)
        ->and($plan['counts']['new'])->toBe(1)
        ->and(productPlanDatabaseState())->toBe($before);
});

test('unlabelled leading column containing non-photo content is still rejected', function () {
    $path = productPlanLegacyPhotoWorkbook('unexpected data');
    expect(fn () => $this->planner->plan($path))
        ->toThrow(\InvalidArgumentException::class, 'Заполненная колонка без заголовка: A3');
});


test('legacy Excel accepts the exact missing-photo marker without creating a photo URL', function (string $marker) {
    $path = productPlanLegacyPhotoWorkbook($marker);
    $before = productPlanDatabaseState();
    $entries = iterator_to_array(app(SpreadsheetReader::class)->catalogRows($path), false);
    $plan = $this->planner->plan($path);

    expect($entries[0]['row']['_photo_urls'])->toBe([])
        ->and($entries[0]['row'][0])->toBe('')
        ->and($plan['counts']['expected'])->toBe(1)
        ->and($plan['counts']['new'])->toBe(1)
        ->and(productPlanDatabaseState())->toBe($before);
})->with(['нет изображения', 'НЕТ изображения']);

test('unlabelled leading non-photo text is still rejected after allowing missing-photo marker', function () {
    $path = productPlanLegacyPhotoWorkbook('изображение не найдено');

    expect(fn () => $this->planner->plan($path))
        ->toThrow(\InvalidArgumentException::class, 'Заполненная колонка без заголовка: A3');
});
