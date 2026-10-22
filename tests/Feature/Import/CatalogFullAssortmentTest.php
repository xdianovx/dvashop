<?php

use App\Enums\ImportRunStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogConfigurationInstallation;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportContext;
use App\Services\Catalog\CatalogImportDatabaseGuards;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Import\CatalogImportProductPlan;
use App\Services\Import\CatalogImportVariantPlan;
use App\Services\Media\DefaultProductImageService;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (getenv('FIRST_FULL_MYSQL_TEST') === '1') {
        expect(DB::getDriverName())->toBe('mysql')
            ->and(DB::connection()->getConfig('host'))->toBe('dvashop_safe_partial_mysql_fix')
            ->and(DB::connection()->getDatabaseName())->toBe('dvashop_guard_test');
    }
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
});

function fullFixture(bool $exceptions = true, bool $protections = true): array
{
    $csv = "Название,Профиль,Положение,Материал,Толщина металла,Цена\nУсилитель,-,Левый,ХКС,1 мм,900\n";
    Storage::disk('local')->put('full.csv', $csv);
    $matrix = app(CatalogConfigurations::class);
    $matrix->install(Storage::disk('local')->path('full.csv'));
    $make = VehicleMake::factory()->create(['title' => 'Example', 'norm_key' => 'example']);
    $products = [];
    $generations = [];
    foreach ($exceptions ? ['Present', 'Partial', 'Conflict', 'Absent', 'Manual', 'Protected'] : ['Present'] as $name) {
        $model = VehicleModel::factory()->forMake($make)->create(['title' => $name, 'norm_key' => strtolower($name)]);
        $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => '1', 'norm_key' => '1-2000-2005-sedan-4-dv',
            'years_label' => '2000-2005', 'body' => 'Седан 4 дв.', 'platform' => null, 'restyling' => null, 'import_source' => 'catalog']);
        $p = Product::factory()->create(['part_type_id' => PartType::where('full_slug', 'usilitel')->value('id'),
            'import_source' => 'catalog', 'import_key' => 'catalog:example:'.strtolower($name).':1-2000-2005-sedan-4-dv:usilitel',
            'status' => $name === 'Manual' ? 'draft' : 'active', 'publication_override' => $name === 'Manual' ? 'draft' : null,
            'import_present' => true, 'description' => 'Manual description to preserve']);
        DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $generation->id, 'is_primary' => true]);
        $matrix->synchronize($p);
        $products[$name] = $p->fresh();
        $generations[$name] = $generation;
    }
    if ($exceptions) {
        $legacy = Product::factory()->create(['part_type_id' => PartType::where('full_slug', 'porog')->value('id'),
            'import_source' => 'catalog', 'import_key' => 'catalog:example:present:1-2000-2005-sedan-4-dv:porog', 'status' => 'active', 'import_present' => true]);
        DB::table('product_fitments')->insert(['product_id' => $legacy->id, 'vehicle_generation_id' => $generations['Present']->id, 'is_primary' => true]);
        $products['Legacy'] = $legacy;
    }
    $writer = new Writer;
    $xlsx = Storage::disk('local')->path('full.xlsx');
    $writer->openToFile($xlsx);
    $writer->addRow(Row::fromValues(array_fill(0, 7, null)));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Усилитель']));
    foreach ($exceptions ? ['Present', 'Partial', 'Conflict', 'Manual', 'Protected'] : ['Present'] as $name) {
        $writer->addRow(Row::fromValues(['', 'Example', $name, $name === 'Partial' ? '' : ($name === 'Conflict' ? 2 : 1), '2000-2005', 'Седан 4 дв.', 1]));
    }
    $writer->close();
    if ($exceptions && $protections) {
        $prior = ImportRun::factory()->create(['import_mode' => 'safe_partial', 'status' => ImportRunStatus::Done]);
        $ids = array_fill_keys(array_map(fn ($name) => $products[$name]->id, ['Partial', 'Conflict', 'Absent', 'Protected', 'Legacy']), 'outside_confirmed_assortment');
        DB::transaction(fn () => app(CatalogImportProtections::class)->persist($prior->id, $ids, 2, str_repeat('a', 64)));
    }
    $admin = User::factory()->superAdmin()->create();

    return compact('products', 'generations', 'admin', 'xlsx');
}

function fullRun(array $fixture, bool $start = true): ImportRun
{
    $run = ImportRun::factory()->create(['import_mode' => 'full', 'status' => ImportRunStatus::Ready, 'stored_path' => 'full.xlsx',
        'file_hash' => hash_file('sha256', $fixture['xlsx']), 'configuration_path' => 'full.csv',
        'configuration_hash' => hash_file('sha256', Storage::disk('local')->path('full.csv')), 'chunk_size' => 2]);
    $installation = app(CatalogConfigurationInstallation::class);
    $review = $installation->previewImport($run);
    $installation->confirmImport($run, $fixture['admin'], $review['confirmation']);
    if ($start) {
        app()->call([new CatalogImportStartJob($run->id), 'handle']);
    }

    return $run->fresh();
}

function fullFinish(ImportRun $run, bool $allowFailure = false): ImportRun
{
    for ($i = 0; $i < 20 && $run->fresh()->status?->isRowsRunning(); $i++) {
        app()->call([new CatalogImportChunkJob($run->id), 'handle']);
    }
    $run = $run->fresh();
    if ($run->status === ImportRunStatus::Failed && ! $allowFailure) {
        throw new RuntimeException($run->last_error ?? json_encode(DB::table('import_logs')->where('import_run_id', $run->id)->get()->all()));
    }

    return $run;
}

function firstFullWorkbook(array $vehicles): array
{
    Storage::disk('local')->put('full.csv', "Название,Профиль,Положение,Материал,Толщина металла,Цена\nУсилитель,-,Левый,ХКС,1 мм,900\n");
    $xlsx = Storage::disk('local')->path('full.xlsx');
    $writer = new Writer;
    $writer->openToFile($xlsx);
    $writer->addRow(Row::fromValues(array_fill(0, 8, null)));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Усилитель', 'Рестайлинг']));
    foreach ($vehicles as $v) {
        $writer->addRow(Row::fromValues(['', ...array_slice($v, 0, 5), 1, $v[5] ?? '']));
    }
    $writer->close();

    return ['xlsx' => $xlsx, 'admin' => User::factory()->superAdmin()->create()];
}

test('first FULL publishes separately approved incoming vehicles across fresh chunks', function (array $vehicles, int $chunkSize): void {
    $f = firstFullWorkbook($vehicles);
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    expect($plan['execution_counts']['new'])->toBe(count($vehicles));
    $run = fullRun($f, false);
    $run->forceFill(['chunk_size' => $chunkSize])->save();
    app()->call([new CatalogImportStartJob($run->id), 'handle']);
    $run = fullFinish($run->fresh());
    expect($run->status, $run->last_error ?? '')->toBe(ImportRunStatus::Done)
        ->and($run->errors_count)->toBe(0)
        ->and(Product::where('status', 'active')->count())->toBe(count($vehicles))
        ->and(Product::pluck('import_key')->sort()->values()->all())->toBe(collect($plan['execution_products'])->pluck('import_key')->sort()->values()->all())
        ->and(DB::table('product_fitments')->distinct()->count('vehicle_generation_id'))->toBe(count($vehicles));
})->with([
    'different brands' => [[['Geo', 'Tracker', '1', '1989-1998', 'Внедорожник 5 дв.'], ['Chevrolet', 'Tracker', '1', '1989-1998', 'Внедорожник 5 дв.']]],
    'different ordinals' => [[['Honda', 'Civic', '3', '2000-2006', 'Седан 4 дв.'], ['Honda', 'Civic', '7', '2000-2006', 'Седан 4 дв.']]],
    'platform labels' => [[['Toyota', 'Crown', '9 (S140Маджеста)', '1991-1995', 'Седан 4 дв.'], ['Toyota', 'Crown', '9 (S140)', '1991-1995', 'Седан 4 дв.']]],
    'restyling and shared applicability' => [[['Mitsubishi', 'Montero', '4', '2006-2014', 'Внедорожник 3 дв.', '4 дорест'], ['Mitsubishi', 'Montero', '4', '2006-2014', 'Внедорожник 3 дв.', '4дорест/4рест']]],
])->with([1, 2]);

test('first FULL ignores historical VW numeric IDs on an unrelated clean catalog and publishes its own keys', function (): void {
    // Seed an unrelated owner of a number used by the deployed historical registry.
    $doc = json_decode(file_get_contents(resource_path('imports/approved-vw-identities.v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry = collect($doc['products'])->firstWhere('part_type_path', 'usilitel');
    $v = $doc['vehicles'][(string) $entry['vehicle_generation_id']]['incoming'];
    $unrelated = Product::factory()->create(['id' => $entry['product_id'], 'import_source' => null, 'import_key' => null, 'status' => 'draft', 'publication_override' => 'draft']);
    $before = $unrelated->fresh()->getRawOriginal();
    $f = firstFullWorkbook([array_values(array_intersect_key($v, array_flip(['make', 'model', 'generation', 'years', 'body'])))]);
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    expect($plan['execution_counts']['new'])->toBe(1)->and($plan['counts']['unresolved'])->toBe(0);
    $run = fullFinish(fullRun($f));
    expect($run->status)->toBe(ImportRunStatus::Done)->and($run->errors_count)->toBe(0)
        ->and(Product::where('import_key', $entry['incoming_key'])->where('status', 'active')->exists())->toBeTrue()
        ->and(Product::where('import_key', $entry['import_key'])->exists())->toBeFalse()
        ->and($unrelated->fresh()->getRawOriginal())->toBe($before);
});

test('FULL never treats a changed historical vehicle as new merely because this run touched it', function (): void {
    $f = fullFixture(false, false);
    $run = fullRun($f);
    $g = $f['generations']['Present'];
    $g->forceFill(['title' => '2', 'norm_key' => '2-2000-2005-sedan-4-dv', 'last_import_run_id' => $run->id])->save();
    $run = fullFinish($run, true);
    expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->errors_count)->toBe(1)
        ->and($f['products']['Present']->fresh()->id)->toBe($f['products']['Present']->id);
});

test('FULL still rejects a competing vehicle introduced by another writer after confirmation', function (): void {
    $f = firstFullWorkbook([['Example', 'New', '1', '2000-2005', 'Седан 4 дв.']]);
    $run = fullRun($f);
    $make = VehicleMake::factory()->create(['title' => 'Example', 'norm_key' => 'example']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'New', 'norm_key' => 'new']);
    VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => '2', 'norm_key' => '2-2000-2005-sedan-4-dv',
        'years_label' => '2000-2005', 'body' => 'Седан 4 дв.', 'import_source' => 'catalog', 'last_import_run_id' => null]);
    $run = fullFinish($run, true);
    expect($run->status)->toBe(ImportRunStatus::Failed)->and($run->errors_count)->toBe(1)
        ->and(Product::where('import_source', 'catalog')->count())->toBe(0);
});

test('FULL separates executable assortment from incomplete Excel and proves absence per ID', function (): void {
    $f = fullFixture();
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    expect($plan['valid'])->toBeFalse()->and($plan['excel_complete'])->toBeFalse()->and($plan['execution_allowed'])->toBeTrue();
    expect(array_keys($plan['full_scope']['archives']))->toBe([$f['products']['Absent']->id]);
    expect(array_keys($plan['full_scope']['preserve']))->toContain($f['products']['Partial']->id, $f['products']['Conflict']->id,
        $f['products']['Protected']->id, $f['products']['Legacy']->id);
    expect($plan['diagnostics']['skipped_rows'][0]['row'])->toBe(4);
    expect(array_intersect(array_keys($plan['full_scope']['preserve']), array_keys($plan['full_scope']['archives'])))->toBe([]);
});

test('FULL skips exceptions archives proved protected absence and preserves IDs media manual holds and protections on replay', function (): void {
    $f = fullFixture();
    $p = $f['products']['Present'];
    Storage::disk('public')->put('preserve.jpg', 'existing manual media');
    DB::table('product_images')->insert(['product_id' => $p->id, 'path' => 'preserve.jpg', 'disk' => 'public', 'is_main' => true,
        'is_visible' => true, 'source_type' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $beforeImage = DB::table('product_images')->where('product_id', $p->id)->get()->toJson();
    $beforeProtection = DB::table('catalog_import_protections')->orderBy('product_id')->get()->toJson();
    $beforeVariant = $f['products']['Absent']->variants()->get()->toJson();
    $run = fullFinish(fullRun($f));
    expect($run->status, $run->last_error ?? '')->toBe(ImportRunStatus::Done)->and($run->errors_count)->toBe(0)->and($run->archived_products)->toBe(1);
    expect($f['products']['Absent']->fresh()->status->value)->toBe('archived')
        ->and((bool) $f['products']['Absent']->fresh()->import_present)->toBeFalse()
        ->and($f['products']['Partial']->fresh()->status->value)->toBe('active')
        ->and($f['products']['Conflict']->fresh()->status->value)->toBe('active')
        ->and($f['products']['Manual']->fresh()->status->value)->toBe('draft')
        ->and($f['products']['Manual']->fresh()->publication_override)->toBe('draft')
        ->and($p->fresh()->description)->toBe('Manual description to preserve');
    expect(DB::table('product_images')->where('product_id', $p->id)->where('path', 'preserve.jpg')->get()->toJson())->toBe($beforeImage);
    Storage::disk('public')->assertExists('preserve.jpg');
    expect($f['products']['Absent']->variants()->get()->toJson())->toBe($beforeVariant);
    expect(DB::table('catalog_import_protections')->orderBy('product_id')->get()->toJson())->toBe($beforeProtection);
    expect($f['generations']['Partial']->fresh()->is_active)->toBeTrue()->and($f['generations']['Absent']->fresh()->is_active)->toBeTrue();
    $ids = Product::orderBy('id')->pluck('id')->all();
    $second = fullFinish(fullRun($f));
    expect($second->status)->toBe(ImportRunStatus::Done)->and($second->archived_products)->toBe(0)
        ->and(Product::orderBy('id')->pluck('id')->all())->toBe($ids);
});

test('FULL preserves both manual publication holds and SKU', function (string $hold): void {
    $f = fullFixture(false, false);
    $p = $f['products']['Present'];
    $p->forceFill(['status' => $hold, 'publication_override' => $hold])->save();
    DB::table('product_variants')->where('product_id', $p->id)->update(['sku' => 'MANUAL-SKU']);
    $run = fullFinish(fullRun($f));
    expect($run->status)->toBe(ImportRunStatus::Done)->and($p->fresh()->status->value)->toBe($hold)
        ->and($p->fresh()->publication_override)->toBe($hold)->and($p->variants()->first()->sku)->toBe('MANUAL-SKU');
})->with(['draft', 'archived']);

test('FULL prunes unused unprotected applicability and keeps source draft and uncertain vehicles', function (): void {
    $f = fullFixture(true, false);
    $run = fullFinish(fullRun($f));
    expect($run->status)->toBe(ImportRunStatus::Done)->and($f['generations']['Absent']->fresh()->is_active)->toBeFalse()
        ->and($f['generations']['Manual']->fresh()->is_active)->toBeTrue()->and($f['generations']['Partial']->fresh()->is_active)->toBeTrue();
});

test('FULL preview and execution use uploaded CSV rather than installed prices and prune unused unprotected vehicles', function (): void {
    $f = fullFixture(false, false);
    $p = $f['products']['Present'];
    $csv = Storage::disk('local')->path('full.csv');
    file_put_contents($csv, str_replace(',900', ',1200', file_get_contents($csv)));
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    $forecast = app(CatalogImportVariantPlan::class)->plan($csv, 'full', $plan);
    expect($forecast['valid'])->toBeTrue()->and($forecast['counts']['variant_prices_change'])->toBe(1);
    expect($p->variants()->first()->price)->toBe('900.00');
    $run = fullFinish(fullRun($f));
    expect($run->status, $run->last_error ?? '')->toBe(ImportRunStatus::Done)->and($p->variants()->first()->price)->toBe('1200.00');
});

test('FULL stale absence evidence cannot archive changed unprotected historical products', function (): void {
    $f = fullFixture(true, false);
    $run = fullRun($f);
    $f['products']['Absent']->update(['description' => 'Changed after review']);
    $run = fullFinish($run, true);
    expect($run->status)->toBe(ImportRunStatus::Failed)->and($f['products']['Absent']->fresh()->status->value)->toBe('active')
        ->and($run->archived_products)->toBe(0);
});

test('FULL may reactivate an unprotected source vehicle but cannot overwrite a protected inactive applicability', function (bool $protected): void {
    $f = fullFixture(false, false);
    $g = $f['generations']['Present'];
    $g->update(['is_active' => false]);
    if ($protected) {
        $prior = ImportRun::factory()->create(['status' => ImportRunStatus::Done]);
        DB::transaction(fn () => app(CatalogImportProtections::class)->persist($prior->id, [$f['products']['Present']->id => 'hold'], 2, str_repeat('a', 64)));
    }
    $run = fullFinish(fullRun($f));
    expect($run->status)->toBe(ImportRunStatus::Done)->and($g->fresh()->is_active)->toBe(! $protected);
})->with([false, true]);

test('archive capability cannot modify protected prices keys other IDs variants or remove protections', function (): void {
    $f = fullFixture();
    $run = fullRun($f);
    $prepared = $run->prepared_rows;
    $run->forceFill(['prepared_rows' => $prepared + ['full_archive_nonce' => 'test-nonce']])->save();
    $absent = $f['products']['Absent'];
    $other = $f['products']['Protected'];
    CatalogImportContext::archive($run->id, 'test-nonce', function () use ($absent, $other): void {
        foreach ([['id' => $absent->id, 'values' => ['status' => 'archived', 'import_present' => 0, 'price' => 1]],
            ['id' => $absent->id, 'values' => ['status' => 'archived', 'import_present' => 0, 'import_key' => 'forged']],
            ['id' => $other->id, 'values' => ['status' => 'archived', 'import_present' => 0]]] as $write) {
            expect(fn () => DB::table('products')->where('id', $write['id'])->update($write['values']))->toThrow(QueryException::class, 'catalog import protection');
        }
        expect(fn () => DB::table('product_variants')->where('product_id', $absent->id)->update(['price' => 1]))->toThrow(QueryException::class);
        expect(fn () => DB::table('catalog_import_protections')->where('product_id', $absent->id)->delete())->toThrow(QueryException::class);
    });
    expect(fn () => DB::table('products')->where('id', $absent->id)->update(['status' => 'archived', 'import_present' => 0]))->toThrow(QueryException::class);
    expect($absent->fresh()->status->value)->toBe('active');
    expect(app(CatalogImportDatabaseGuards::class)->fullArchiveReady())->toBeTrue();
});

test('FULL preserves an orphan despite a matching import key and blocks matrix changes affecting that exception', function (): void {
    $f = fullFixture(false, false);
    DB::table('product_fitments')->where('product_id', $f['products']['Present']->id)->delete();
    $plan = app(CatalogImportProductPlan::class)->full($f['xlsx']);
    expect($plan['execution_counts']['updates'])->toBe(0)
        ->and($plan['full_scope']['preserve'][$f['products']['Present']->id])->toBe('historical_relation_requires_decision');
    $csv = Storage::disk('local')->path('full.csv');
    file_put_contents($csv, str_replace(',900', ',1200', file_get_contents($csv)));
    $run = ImportRun::factory()->create(['import_mode' => 'full', 'stored_path' => 'full.xlsx',
        'file_hash' => hash_file('sha256', $f['xlsx']), 'configuration_path' => 'full.csv', 'configuration_hash' => hash_file('sha256', $csv)]);
    $review = app(CatalogConfigurationInstallation::class)->previewImport($run);
    expect($review['execution_allowed'])->toBeFalse()->and($review['installation']['blocked_types'])->toContain('usilitel');
});

test('additive archive guard upgrade preserves all 54 guards and existing permanent protections', function (): void {
    $f = fullFixture();
    $name = 'cip_'.substr(hash('sha256', 'products'), 0, 12).'_update';
    if (DB::getDriverName() === 'mysql') {
        DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON products FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='legacy protection'");
    } else {
        DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON products BEGIN SELECT RAISE(ABORT,'legacy protection'); END");
    }
    DB::unprepared("DROP TRIGGER {$name}_archive_v3");
    expect(app(CatalogImportDatabaseGuards::class)->fullArchiveReady())->toBeFalse();
    $before = DB::table('catalog_import_protections')->orderBy('product_id')->get()->toJson();
    $migration = require database_path('migrations/2026_10_20_000300_allow_proven_full_import_archiving.php');
    $migration->up();
    expect(app(CatalogImportDatabaseGuards::class)->fullArchiveReady())->toBeTrue();
    $count = DB::getDriverName() === 'mysql'
        ? DB::table('information_schema.triggers')->where('trigger_schema', DB::connection()->getDatabaseName())->count()
        : DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'cip_%')->count();
    expect($count)->toBe(54)->and(DB::table('catalog_import_protections')->orderBy('product_id')->get()->toJson())->toBe($before);
    $run = fullFinish(fullRun($f));
    expect($run->status)->toBe(ImportRunStatus::Done)->and($run->archived_products)->toBe(1);
});

test('FULL resolves an empty shared image key without changing the protected or unprotected type', function (bool $protected): void {
    $f = fullFixture(false, false);
    $type = PartType::where('full_slug', 'usilitel')->firstOrFail();
    $type->update(['default_image_key' => null]);
    if ($protected) {
        $prior = ImportRun::factory()->create(['status' => ImportRunStatus::Done]);
        DB::transaction(fn () => app(CatalogImportProtections::class)->persist($prior->id, [$f['products']['Present']->id => 'shared type'], 2, str_repeat('a', 64)));
    }
    $writer = new Writer;
    $writer->openToFile($f['xlsx']);
    $writer->addRow(Row::fromValues(array_fill(0, 7, null)));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Усилитель']));
    foreach (['Present', 'New'] as $model) {
        $writer->addRow(Row::fromValues(['', 'Example', $model, 1, '2000-2005', 'Седан 4 дв.', 1]));
    }
    $writer->close();
    $before = $type->fresh()->getRawOriginal();
    $run = fullFinish(fullRun($f));
    expect($run->status)->toBe(ImportRunStatus::Done)
        ->and($run->created_products)->toBe(1)
        ->and($type->fresh()->getRawOriginal())->toBe($before)
        ->and(app(DefaultProductImageService::class)->keyForPartType($type->fresh()))->toBe('usilitel');
    $new = Product::where('import_key', 'catalog:example:new:1-2000-2005-sedan-4-dv:usilitel')->firstOrFail();
    expect($new->defaultVariant->price)->toBe('900.00');
})->with([false, true]);
