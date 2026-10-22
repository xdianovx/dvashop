<?php

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Import\CatalogImportApprovedIdentities;
use App\Services\Import\CatalogImportExpectedProducts;
use App\Services\Import\CatalogImportIdentity;
use App\Services\Import\CatalogImportProductPlan;
use App\Services\Import\CatalogImportSnapshot;
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
    if (getenv('FIRST_FULL_MYSQL_TEST') === '1') {
        expect(DB::getDriverName())->toBe('mysql')
            ->and(DB::connection()->getConfig('host'))->toBe('dvashop_safe_partial_mysql_fix')
            ->and(DB::connection()->getDatabaseName())->toBe('dvashop_guard_test');
    }
    Storage::fake('local');
    Storage::fake('public');
    Bus::fake();
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
});

function vw3Fixture(?string $override = null): array
{
    $make = VehicleMake::factory()->create(['title' => 'Volkswagen', 'norm_key' => 'volkswagen', 'slug' => 'volkswagen']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Amarok', 'norm_key' => 'amarok', 'slug' => 'amarok']);
    $g = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => '1', 'norm_key' => '1-2010-2016-pikap-4-dv', 'slug' => 'historical-amarok-url',
        'years_label' => '2010-2016', 'body' => 'Пикап 4 дв.', 'platform' => null, 'restyling' => null, 'import_source' => 'catalog',
    ])->load('model.make');
    $part = PartType::where('full_slug', 'usilitel')->firstOrFail();
    $p = Product::factory()->create([
        'import_source' => 'catalog', 'import_key' => 'catalog:volkswagen:amarok:1-2010-2016-pikap-4-dv:usilitel',
        'part_type_id' => $part->id, 'product_category_id' => $part->productCategory->id,
        'slug' => 'historical-product-url', 'status' => $override ?? 'active', 'publication_override' => $override,
        'price' => 1234, 'price_mode' => 'unknown',
    ]);
    DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $g->id, 'note' => null, 'is_primary' => true]);
    $incoming = ['make' => 'VW', 'model' => 'Amarok', 'generation' => '1', 'years' => '2010-2016',
        'body' => 'Пикап 4 дв.', 'platform' => '', 'restyling' => ''];
    $historical = ['id' => $g->id, 'title' => $g->title, 'norm_key' => $g->norm_key, 'years_label' => $g->years_label,
        'body' => $g->body, 'platform' => null, 'restyling' => null, 'import_source' => 'catalog',
        'model_id' => $model->id, 'model' => $model->title, 'model_key' => $model->norm_key,
        'make_id' => $make->id, 'make' => $make->title, 'make_key' => $make->norm_key];
    $entry = ['incoming_key' => 'catalog:vw:amarok:1-2010-2016-pikap-4-dv:usilitel', 'product_id' => $p->id,
        'import_key' => $p->import_key, 'source' => 'catalog', 'part_type_id' => $part->id, 'part_type_path' => $part->full_slug,
        'vehicle_generation_id' => $g->id, 'fitment' => ['note' => null, 'is_primary' => true]];
    $doc = ['version' => 1, 'scope' => 'vw_to_volkswagen_id3a', 'approved_count' => 1,
        'vehicles' => [(string) $g->id => ['incoming' => $incoming, 'historical' => $historical]], 'products' => [$entry]];
    $manifest = Storage::disk('local')->path('approved-test.json');
    file_put_contents($manifest, json_encode($doc, JSON_THROW_ON_ERROR));
    app()->instance(CatalogImportApprovedIdentities::class, new CatalogImportApprovedIdentities($manifest));
    $row = [0 => '', 1 => 'VW', 2 => 'Amarok', 3 => '1', 4 => '2010-2016', 5 => 'Пикап 4 дв.', 6 => 1,
        '_platform' => '', '_restyling' => '', '_catalog_v2' => true, '_details' => [6 => 1], '_row_number' => 3, '_photo_urls' => []];
    $path = Storage::disk('local')->path('vw.xlsx');
    $w = new Writer;
    $w->openToFile($path);
    $w->getCurrentSheet()->setName('Автомобили');
    $w->addRow(Row::fromValues(array_fill(0, 7, null)));
    $w->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Усилитель']));
    $w->addRow(Row::fromValues(array_slice($row, 0, 7)));
    $w->close();

    return compact('g', 'p', 'part', 'entry', 'doc', 'manifest', 'row', 'path');
}

test('reviewed VW pair is shared by preview expected assortment snapshot and actual processor', function (): void {
    extract(vw3Fixture());
    $plan = app(CatalogImportProductPlan::class)->plan($path);
    $expected = app(CatalogImportExpectedProducts::class)->collect([$row], [6 => ['part_type_full_slug' => 'usilitel']], 'catalog');
    expect($plan['valid'])->toBeTrue()->and($plan['counts']['new'])->toBe(0)
        ->and($plan['expected_products'][0]['product_id'])->toBe($p->id)
        ->and($plan['expected_products'][0]['import_key'])->toBe($p->import_key)
        ->and($expected['products'])->toHaveKey($p->import_key);

    $reader = app(SpreadsheetReader::class);
    $run = ImportRun::factory()->create(['type' => 'catalog', 'status' => ImportRunStatus::RunningRows, 'total_rows' => 1]);
    $prepared = $reader->prepareRows($path);
    expect(app(CatalogImportSnapshot::class)->prepare($run, $prepared, $reader->readMergedDetailHeaders($path), $reader))->toBe(1)
        ->and(DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->value('import_key'))->toBe($p->import_key);
    $before = $g->fresh()->getRawOriginal();
    $generation = app(ImportRowProcessor::class)->vehicleGeneration('VW', 'Amarok', '1', '2010-2016', 'Пикап 4 дв.', modern: true);
    expect($generation->id)->toBe($g->id)->and($g->fresh()->getRawOriginal())->toBe($before)
        ->and(VehicleMake::where('norm_key', 'vw')->exists())->toBeFalse();
});

test('real approved row keeps product ID key slug manual status fitments variants SKU and price', function (?string $override): void {
    extract(vw3Fixture($override));
    // Seed real CSV offers through the same synchronizer used by the importer.
    // A matching, already-synchronized offer must survive the approved VW row unchanged.
    $configurations = app(CatalogConfigurations::class);
    $configurations->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    $configurations->synchronize($p, 'test:seed-approved-vw');
    $v = $p->variants()->where('is_default', true)->firstOrFail();
    $v->forceFill(['sku' => 'VW-HIST-123'])->saveQuietly();
    $priceBefore = $p->fresh()->price;
    $variantsBefore = $p->variants()->orderBy('id')->get()
        ->map(fn ($variant) => $variant->getRawOriginal())->all();
    $fitmentsBefore = DB::table('product_fitments')->get()->toJson();
    $generationBefore = $g->fresh()->getRawOriginal();
    $run = ImportRun::factory()->create(['type' => 'catalog', 'status' => ImportRunStatus::RunningRows, 'total_rows' => 1]);
    $headers = [6 => ['index' => 6, 'title' => 'Усилитель', 'part_type_id' => $part->id,
        'part_type_full_slug' => 'usilitel', 'product_category_id' => $part->productCategory->id]];
    app(ImportRowProcessor::class)->process($run, $row, $headers, 3);
    expect(Product::count())->toBe(1)->and($p->fresh()->import_key)->toBe($entry['import_key'])
        ->and($p->fresh()->slug)->toBe('historical-product-url')->and($p->fresh()->price)->toBe($priceBefore)
        ->and($p->fresh()->status->value)->toBe($override ?? 'active')
        ->and($p->fresh()->publication_override)->toBe($override)
        ->and($v->fresh()->sku)->toBe('VW-HIST-123')
        ->and($p->variants()->orderBy('id')->get()->map(fn ($variant) => $variant->getRawOriginal())->all())->toBe($variantsBefore)
        ->and(DB::table('product_fitments')->get()->toJson())->toBe($fitmentsBefore)
        ->and($g->fresh()->getRawOriginal())->toBe($generationBefore)
        ->and(VehicleMake::where('norm_key', 'vw')->exists())->toBeFalse()
        ->and(Product::where('import_key', $entry['incoming_key'])->exists())->toBeFalse();
})->with([null, 'archived', 'draft']);

test('unapproved VW part and EUR market never adopt an approved vehicle product', function (string $kind): void {
    extract(vw3Fixture());
    $r = $row;
    if ($kind === 'market') {
        $r[1] = 'VW EUR';
    }
    $path = $kind === 'part' ? 'soedinitel' : 'usilitel';
    $resolution = app(CatalogImportIdentity::class)->resolve($r, $path, 'catalog');
    expect($resolution['error'])->not->toBeNull()->and($resolution['product_id'])->toBeNull()
        ->and($resolution['code'])->toBe('make_or_market_change');
})->with(['part', 'market']);

test('changed approved historical facts fail closed instead of creating a replacement', function (string $change): void {
    extract(vw3Fixture());
    match ($change) {
        'missing' => DB::table('products')->where('id', $p->id)->delete(),
        'source' => DB::table('products')->where('id', $p->id)->update(['import_source' => 'other']),
        'key' => DB::table('products')->where('id', $p->id)->update(['import_key' => 'catalog:changed']),
        'type' => DB::table('products')->where('id', $p->id)->update(['part_type_id' => PartType::where('full_slug', 'soedinitel')->value('id')]),
        'generation' => DB::table('vehicle_generations')->where('id', $g->id)->update(['title' => '2']),
        'fitment' => DB::table('product_fitments')->where('product_id', $p->id)->update(['note' => 'different applicability']),
        'deleted' => DB::table('products')->where('id', $p->id)->update(['deleted_at' => now()]),
    };
    $resolution = app(CatalogImportIdentity::class)->resolve($row, 'usilitel', 'catalog');
    expect($resolution['code'])->toBe('approved_identity_conflict')->and($resolution['error'])->not->toBeNull()
        ->and($resolution['product_id'])->toBeNull();
    $before = DB::table('products')->count();
    $run = ImportRun::factory()->create(['type' => 'catalog']);
    expect(fn () => app(ImportRowProcessor::class)->process($run, $row, [6 => ['part_type_full_slug' => 'usilitel']], 3))
        ->toThrow(RuntimeException::class);
    expect(DB::table('products')->count())->toBe($before)->and(VehicleMake::where('norm_key', 'vw')->exists())->toBeFalse();
})->with(['missing', 'source', 'key', 'type', 'generation', 'fitment', 'deleted']);

test('a competing product blocks approved identity including a manual owner of the new key', function (string $kind): void {
    extract(vw3Fixture());
    $q = Product::factory()->create(['part_type_id' => $part->id, 'import_source' => null,
        'import_key' => $kind === 'key' ? $entry['incoming_key'] : null]);
    if ($kind === 'fitment') {
        DB::table('product_fitments')->insert(['product_id' => $q->id, 'vehicle_generation_id' => $g->id, 'is_primary' => true]);
    }
    expect(app(CatalogImportIdentity::class)->resolve($row, 'usilitel', 'catalog')['code'])->toBe('approved_identity_conflict');
})->with(['key', 'fitment']);

test('a competing generation blocks the mapping even if an exact vehicle still exists', function (): void {
    extract(vw3Fixture());
    VehicleGeneration::factory()->forVehicleModel($g->model)->create([
        'title' => '2', 'norm_key' => 'other-ordinal', 'years_label' => $g->years_label, 'body' => $g->body,
    ]);
    expect(app(CatalogImportIdentity::class)->resolve($row, 'usilitel', 'catalog')['code'])->toBe('approved_identity_conflict');
});

test('a changed incoming platform or joint description rejects the approved pair', function (string $field): void {
    extract(vw3Fixture());
    $row[$field] = 'changed';
    expect(app(CatalogImportIdentity::class)->resolve($row, 'usilitel', 'catalog')['code'])->toBe('approved_identity_conflict');
})->with(['_platform', '_restyling']);

test('one historical ID cannot be assigned to two incoming keys in the permanent registry', function (): void {
    extract(vw3Fixture());
    $doc['products'][] = array_replace($entry, ['incoming_key' => 'catalog:vw:other:1:usilitel',
        'import_key' => 'catalog:volkswagen:other:1:usilitel']);
    $doc['approved_count'] = 2;
    file_put_contents($manifest, json_encode($doc, JSON_THROW_ON_ERROR));
    expect(fn () => (new CatalogImportApprovedIdentities($manifest))->entry($entry['incoming_key']))
        ->toThrow(RuntimeException::class, 'один старый ID');
});

test('a missing permanent registry blocks instead of falling back to VW creation', function (): void {
    extract(vw3Fixture());
    unlink($manifest);
    expect(fn () => app(CatalogImportIdentity::class)->resolve($row, 'usilitel', 'catalog'))
        ->toThrow(RuntimeException::class, 'Отсутствует постоянный реестр');
});

test('read-only approved planning is deterministic and changes no tables files or queue', function (): void {
    extract(vw3Fixture('draft'));
    $schema = DB::getDriverName() === 'mysql' ? DB::connection()->getDatabaseName() : null;
    $state = fn () => collect(DB::getSchemaBuilder()->getTableListing($schema))->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
    $before = $state();
    $files = collect(['local', 'public'])->mapWithKeys(fn ($disk) => [$disk => collect(Storage::disk($disk)->allFiles())
        ->mapWithKeys(fn ($file) => [$file => hash_file('sha256', Storage::disk($disk)->path($file))])->all()])->all();
    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });
    $planner = app(CatalogImportProductPlan::class);
    $first = $planner->plan($path);
    expect($planner->plan($path))->toBe($first)->and($first['counts']['existing'])->toBe(1)
        ->and($first['counts']['preserve_manual'])->toBe(1)->and($state())->toBe($before)->and($writes)->toBe([]);
    foreach ($files as $disk => $hashes) {
        expect(Storage::disk($disk)->allFiles())->toBe(array_keys($hashes));
        foreach ($hashes as $file => $hash) {
            expect(hash_file('sha256', Storage::disk($disk)->path($file)))->toBe($hash);
        }
    }
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('the deployed registry contains only the 558 reviewed distinct VW pairs', function (): void {
    $doc = json_decode(file_get_contents(resource_path('imports/approved-vw-identities.v1.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($doc['products'])->toHaveCount(558)
        ->and(array_unique(array_column($doc['products'], 'product_id')))->toHaveCount(558)
        ->and(array_unique(array_column($doc['products'], 'incoming_key')))->toHaveCount(558)
        ->and($doc['vehicles'])->toHaveCount(55);
    foreach ($doc['products'] as $entry) {
        expect($entry['incoming_key'])->toStartWith('catalog:vw:')
            ->and($entry['import_key'])->toBe('catalog:volkswagen:'.substr($entry['incoming_key'], strlen('catalog:vw:')));
    }
    expect($doc['provenance']['excel_sha256'])->toBe('ff782fbe604ec38a7372817c4b0520738b385e49fee2ae1f6c72b25c6b543fb9');
});
