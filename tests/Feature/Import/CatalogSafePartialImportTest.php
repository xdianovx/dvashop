<?php

use App\Enums\ImportRunStatus;
use App\Filament\Pages\CatalogImportPage;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Jobs\CatalogSafePartialJob;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Catalog\CatalogPublicationPolicy;
use App\Services\Import\CatalogImportApprovedIdentities;
use App\Services\Import\CatalogImportSnapshot;
use App\Services\Import\CatalogSafePartialImport;
use App\Services\Import\CatalogSafePartialPlan;
use App\Services\Import\ImportImageDownloader;
use App\Services\Import\ImportProductFactory;
use App\Services\ImportStatusService;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Bus::fake();
    Queue::fake();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
});

function safeRunFixture(bool $vw = false): array
{
    $csv = Storage::disk('local')->path('matrix.csv');
    $in = fopen(base_path('tests/Fixtures/catalog-configurations.csv'), 'rb');
    $out = fopen($csv, 'wb');
    $first = true;
    while (($r = fgetcsv($in, null, ',', '"', '')) !== false) {
        if ($first || trim($r[0]) === 'Усилитель') {
            fputcsv($out, $r, ',', '"', '');
        }
        $first = false;
    }
    fclose($in);
    fclose($out);
    $matrix = app(CatalogConfigurations::class);
    $matrix->install($csv);
    $part = PartType::where('full_slug', 'usilitel')->firstOrFail();
    $make = VehicleMake::factory()->create(['title' => $vw ? 'Volkswagen' : 'Example', 'norm_key' => $vw ? 'volkswagen' : 'example']);
    $products = [];
    $generations = [];
    foreach (['Stable', 'Conflict', 'Incomplete'] as $title) {
        $model = VehicleModel::factory()->forMake($make)->create(['title' => $title, 'norm_key' => strtolower($title)]);
        $g = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => '1', 'years_label' => '2000-2005',
            'body' => 'Седан 4 дв.', 'platform' => null, 'restyling' => null, 'import_source' => 'catalog',
            'norm_key' => '1-2000-2005-sedan-4-dv']);
        $p = Product::factory()->create(['part_type_id' => $part->id, 'import_source' => 'catalog',
            'import_key' => 'catalog:'.$make->norm_key.':'.strtolower($title).':1-2000-2005-sedan-4-dv:usilitel',
            'status' => 'active', 'price_mode' => 'unknown', 'slug' => 'saved-'.strtolower($title), 'description' => 'Manual description']);
        DB::table('product_fitments')->insert(['product_id' => $p->id, 'vehicle_generation_id' => $g->id, 'is_primary' => true]);
        $matrix->synchronize($p);
        $products[$title] = $p->fresh();
        $generations[$title] = $g;
    }
    $legacy = Product::factory()->create(['part_type_id' => PartType::where('full_slug', 'porog')->value('id'),
        'import_source' => 'catalog', 'import_key' => 'catalog:legacy-absent-type', 'status' => 'active', 'price_mode' => 'unknown']);
    if ($vw) {
        $p = $products['Stable'];
        $g = $generations['Stable'];
        $m = $g->model;
        $incoming = ['make' => 'VW', 'model' => 'Stable', 'generation' => '1', 'years' => '2000-2005', 'body' => 'Седан 4 дв.', 'platform' => '', 'restyling' => ''];
        $h = ['id' => $g->id, 'title' => $g->title, 'norm_key' => $g->norm_key, 'years_label' => $g->years_label, 'body' => $g->body,
            'platform' => null, 'restyling' => null, 'import_source' => 'catalog', 'model_id' => $m->id, 'model' => $m->title,
            'model_key' => $m->norm_key, 'make_id' => $make->id, 'make' => 'Volkswagen', 'make_key' => 'volkswagen'];
        $e = ['incoming_key' => str_replace('catalog:volkswagen:', 'catalog:vw:', $p->import_key), 'product_id' => $p->id,
            'import_key' => $p->import_key, 'source' => 'catalog', 'part_type_id' => $part->id, 'part_type_path' => 'usilitel',
            'vehicle_generation_id' => $g->id, 'fitment' => ['note' => null, 'is_primary' => true]];
        $doc = ['version' => 1, 'scope' => 'vw_to_volkswagen_id3a', 'approved_count' => 1,
            'vehicles' => [$g->id => ['incoming' => $incoming, 'historical' => $h]], 'products' => [$e]];
        $path = Storage::disk('local')->path('approved.json');
        file_put_contents($path, json_encode($doc, JSON_THROW_ON_ERROR));
        app()->instance(CatalogImportApprovedIdentities::class, new CatalogImportApprovedIdentities($path));
    }
    $excel = Storage::disk('local')->path('source.xlsx');
    $w = new Writer;
    $w->openToFile($excel);
    $w->getCurrentSheet()->setName('Любое название');
    $w->addRow(Row::fromValues(array_fill(0, 7, null)));
    $w->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы', 'Кузов', 'Усилитель']));
    foreach ([
        ['', $vw ? 'VW' : 'Example', 'Stable', 1, '2000-2005', 'Седан 4 дв.', 1],
        ['', $vw ? 'Volkswagen' : 'Example', 'Conflict', 2, '2000-2005', 'Седан 4 дв.', 1],
        ['', $vw ? 'Volkswagen' : 'Example', 'Incomplete', '', '2000-2005', 'Седан 4 дв.', 1],
        ['', 'BrandNew', 'Absent Model', 1, '2020-2025', 'Седан 4 дв.', 1],
    ] as $r) {
        $w->addRow(Row::fromValues($r));
    }
    $w->close();
    $run = ImportRun::factory()->create(['type' => 'catalog', 'import_mode' => 'safe_partial', 'status' => ImportRunStatus::Ready,
        'stored_path' => 'source.xlsx', 'file_hash' => hash_file('sha256', $excel),
        'configuration_path' => 'matrix.csv', 'configuration_hash' => hash_file('sha256', $csv), 'chunk_size' => 1]);
    $admin = User::factory()->superAdmin()->create();

    return compact('run', 'admin', 'products', 'generations', 'legacy', 'excel', 'csv');
}

function safeApprove(array $f): CatalogSafePartialImport
{
    $service = app(CatalogSafePartialImport::class);
    $review = $service->preview($f['run']);
    expect($review['valid'])->toBeTrue();
    $service->confirm($f['run'], $f['admin'], $review['safe_confirmation']);

    return $service;
}

test('inventory activity leaves preview consent and resumed item fingerprints current without overwriting stock', function (): void {
    $f = safeRunFixture();
    $service = app(CatalogSafePartialImport::class);
    $variant = $f['products']['Stable']->variants()->firstOrFail();
    $preview = $service->preview($f['run']);
    $variant->update(['stock_quantity' => 5, 'updated_at' => now()->addSecond()]);
    $service->confirm($f['run'], $f['admin'], $preview['safe_confirmation']);
    $variant->decrement('stock_quantity');
    $service->start($f['run']->fresh());
    $variant->increment('stock_quantity');
    $service->batch($f['run']->fresh(), 0);
    expect($variant->fresh()->stock_quantity)->toBe(5);
    expect(DB::table('catalog_import_protections')->count())->toBe(3);
});

test('new persistent protection invalidates approved scope before start', function (): void {
    $f = safeRunFixture();
    $service = safeApprove($f);
    $run = $f['run']->fresh();
    $allowed = $f['products']['Stable'];
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist(
        $run->id, [$allowed->id => 'new explicit protection'], CatalogSafePartialPlan::VERSION, str_repeat('f', 64)));
    expect(fn () => $service->start($run))->toThrow(ValidationException::class, 'защищённая область');
    expect($run->fresh()->initialized_at)->toBeNull();
});

test('variant semantic changes still invalidate consent', function (string $field, mixed $value): void {
    $f = safeRunFixture();
    $service = safeApprove($f);
    DB::table('product_variants')->where('id', $f['products']['Stable']->variants()->firstOrFail()->id)->update([$field => $value]);
    expect(fn () => $service->start($f['run']->fresh()))->toThrow(ValidationException::class);
})->with([['price', 99999], ['stock_status', 'pre_order'], ['is_active', false], ['sku', 'manual-new-sku']]);

test('safe_partial plan excludes conflicts missing rows and historical types without treating candidates as proven', function (): void {
    $f = safeRunFixture();
    $p = app(CatalogSafePartialPlan::class)->build($f['excel'], $f['csv']);
    expect($p['valid'])->toBeTrue()->and($p['counts'])->toMatchArray(['updates' => 1, 'new' => 1, 'protected_existing' => 3, 'skipped_rows' => 1])
        ->and($p['items'][1]['proof'])->toBe('new_vehicle_model_absent_from_all_historical_makes')
        ->and($p['variant_counts']['variants_create'])->toBeGreaterThan(0)
        ->and(Product::count())->toBe(4);
});

test('safe_partial keeps protected rows offers prices and old types unchanged and creates no duplicate on replay', function (): void {
    $f = safeRunFixture();
    $protected = [$f['products']['Conflict']->id, $f['products']['Incomplete']->id, $f['legacy']->id];
    $state = fn () => [DB::table('products')->whereIn('id', $protected)->orderBy('id')->get()->toJson(),
        DB::table('product_variants')->whereIn('product_id', $protected)->orderBy('id')->get()->toJson()];
    $before = $state();
    $stable = $f['products']['Stable'];
    $oldKey = $stable->import_key;
    $oldSlug = $stable->slug;
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    while ($service->batch($run, $run->fresh()->prepared_rows['last_item_id'])) {
    }
    $run->refresh();
    expect($run->status)->toBe(ImportRunStatus::Done)->and($state())->toBe($before)
        ->and($stable->fresh()->import_key)->toBe($oldKey)->and($stable->fresh()->slug)->toBe($oldSlug)
        ->and($stable->fresh()->description)->toBe('Manual description')
        ->and(Product::count())->toBe(5)
        ->and(DB::table('catalog_import_protections')->count())->toBe(3)
        ->and(app(ImportProductFactory::class)->archiveMissingProducts($run))->toBe(0);
    $after = DB::table('products')->orderBy('id')->get()->toJson();
    expect($service->batch($run, 0))->toBeFalse()
        ->and(DB::table('products')->orderBy('id')->get()->toJson())->toBe($after)
        ->and(fn () => app(CatalogImportSnapshot::class)->finalize($run, fn () => 99))->toThrow(RuntimeException::class)
        ->and(fn () => app(CatalogPublicationPolicy::class)->publishSnapshot($run->id, 'catalog'))->toThrow(RuntimeException::class);
    Queue::assertNothingPushed();
});

test('safe_partial supports approved VW and manual publication holds', function (?string $override): void {
    $f = safeRunFixture(true);
    $stable = $f['products']['Stable'];
    if ($override) {
        $stable->forceFill(['status' => $override, 'publication_override' => $override])->save();
    }
    $id = $stable->id;
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    while ($service->batch($run, $run->fresh()->prepared_rows['last_item_id'])) {
    }
    expect($stable->fresh()->id)->toBe($id)
        ->and($stable->fresh()->import_key)->toStartWith('catalog:volkswagen:')
        ->and($stable->fresh()->status->value)->toBe($override ?? 'active')
        ->and(VehicleMake::where('norm_key', 'vw')->exists())->toBeFalse();
})->with([null, 'archived', 'draft']);

test('confirmation is mandatory and bound to run file and product state', function (string $change): void {
    $f = safeRunFixture();
    $service = app(CatalogSafePartialImport::class);
    if ($change === 'none') {
        expect(fn () => $service->start($f['run']))->toThrow(ValidationException::class);

        return;
    }
    $service = safeApprove($f);
    match ($change) {
        'file' => file_put_contents($f['csv'], "\n", FILE_APPEND),
        'product' => DB::table('products')->where('id', $f['products']['Stable']->id)->update(['description' => 'edited']),
        'matrix' => DB::table('catalog_matrix_sources')->where('id', 1)->update(['sha256' => str_repeat('c', 64)]),
        'scope' => DB::table('catalog_safe_partial_items')->where('import_run_id', $f['run']->id)->update(['payload' => '{}']),
    };
    expect(fn () => $service->start($f['run']->fresh()))->toThrow(ValidationException::class)
        ->and($f['run']->fresh()->initialized_at)->toBeNull()->and(Product::count())->toBe(4);
})->with(['none', 'file', 'product', 'matrix', 'scope']);

test('active partial reservation blocks competing bulk SQL and cancellation retains permanent protections', function (): void {
    $f = safeRunFixture();
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    expect(fn () => DB::table('products')->where('id', $f['products']['Stable']->id)->update(['price' => 1]))
        ->toThrow(QueryException::class, 'reserved');
    $service->cancel($run);
    expect(DB::table('catalog_import_protections')->count())->toBe(3)
        ->and(DB::table('catalog_price_locks')->value('safe_partial_run_id'))->toBeNull()
        ->and(fn () => DB::table('products')->where('id', $f['legacy']->id)->update(['status' => 'archived']))
        ->toThrow(QueryException::class, 'protection');
});

test('failed batch rolls back offers checkpoint and item result and duplicate delivery is a no-op', function (): void {
    $f = safeRunFixture();
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    $before = DB::table('products')->orderBy('id')->get()->toJson();
    $fail = true;
    DB::listen(function ($q) use (&$fail): void {
        if ($fail && preg_match('~^update\s+[^a-z]*catalog_safe_partial_items~i', $q->sql)) {
            $fail = false;
            throw new RuntimeException('injected checkpoint failure');
        }
    });
    expect(fn () => $service->batch($run, 0))->toThrow(RuntimeException::class, 'injected');
    expect($run->fresh()->prepared_rows['last_item_id'])->toBe(0)
        ->and(DB::table('products')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('catalog_safe_partial_items')->whereNotNull('result')->count())->toBe(0);
    expect($service->batch($run, 0))->toBeTrue()->and($service->batch($run, 0))->toBeFalse();
});

test('the same source in a new approved run updates existing identities without duplicates', function (): void {
    $f = safeRunFixture();
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    while ($service->batch($run, $run->fresh()->prepared_rows['last_item_id'])) {
    }
    $ids = Product::orderBy('id')->pluck('id')->all();
    $f['run'] = ImportRun::factory()->create($run->only(['type', 'import_mode', 'stored_path', 'file_hash', 'configuration_path', 'configuration_hash'])
        + ['status' => ImportRunStatus::Ready, 'chunk_size' => 1]);
    $review = $service->preview($f['run']);
    expect($review['counts']['new'])->toBe(0)->and($review['counts']['updates'])->toBe(2);
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    while ($service->batch($run, $run->fresh()->prepared_rows['last_item_id'])) {
    }
    expect(Product::orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(DB::table('catalog_import_protections')->count())->toBe(3)
        ->and($run->fresh()->updated_products)->toBe(2);
});

test('resume and queued batches reject a changed source before additional changes', function (): void {
    $f = safeRunFixture();
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    expect($service->batch($run, 0))->toBeTrue();
    $state = DB::table('products')->orderBy('id')->get()->toJson();
    app(ImportStatusService::class)->pause($run->fresh());
    file_put_contents($f['excel'], 'changed');
    expect(fn () => app(ImportStatusService::class)->resume($run->fresh()))->toThrow(ValidationException::class)
        ->and(DB::table('products')->orderBy('id')->get()->toJson())->toBe($state)
        ->and($run->fresh()->status)->toBe(ImportRunStatus::Paused);
});

test('queue delivery cannot replace approval and neither legacy job can bypass the partial path', function (string $job): void {
    $f = safeRunFixture();
    $state = DB::table('products')->orderBy('id')->get()->toJson();
    app()->call([new $job($f['run']->id), 'handle']);
    expect($f['run']->fresh()->status)->toBe($job === CatalogImportChunkJob::class ? ImportRunStatus::Ready : ImportRunStatus::Paused)
        ->and($f['run']->fresh()->initialized_at)->toBeNull()
        ->and(DB::table('products')->orderBy('id')->get()->toJson())->toBe($state);
})->with([CatalogSafePartialJob::class, CatalogImportStartJob::class, CatalogImportChunkJob::class]);

test('the administrator sees the complete partial scope and explicitly approves before dispatch', function (): void {
    $f = safeRunFixture();
    $this->actingAs($f['admin']);
    $page = Livewire::test(CatalogImportPage::class)
        ->call('start', $f['run']->id)->assertHasNoErrors()
        ->assertSet('configurationReview.mode', 'safe_partial')
        ->assertSet('configurationReview.counts.updates', 1)
        ->assertSet('configurationReview.counts.new', 1)
        ->assertSet('configurationReview.counts.protected_existing', 3)
        ->assertSee('Глобальное архивирование отключено.')
        ->assertSee('Изменения цен вариантов');
    expect($f['run']->fresh()->configuration_confirmed_at)->toBeNull()->and(Product::count())->toBe(4);
    $page->call('confirmConfigurationImport')->assertHasNoErrors();
    expect($f['run']->fresh()->configuration_confirmed_by)->toBe($f['admin']->id)
        ->and($f['run']->fresh()->status)->toBe(ImportRunStatus::RunningRows)
        ->and(Product::count())->toBe(4);
    Bus::assertDispatched(CatalogSafePartialJob::class, 1);
});

test('new parts cannot silently reactivate an inactive historical applicability', function (): void {
    $f = safeRunFixture();
    $model = VehicleModel::factory()->create(['title' => 'Absent Model', 'norm_key' => 'absent-model',
        'vehicle_make_id' => VehicleMake::factory()->create(['title' => 'BrandNew', 'norm_key' => 'brandnew'])->id]);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => '1',
        'norm_key' => '1-2020-2025-sedan-4-dv', 'years_label' => '2020-2025', 'body' => 'Седан 4 дв.',
        'platform' => null, 'restyling' => null, 'is_active' => false]);
    $plan = app(CatalogSafePartialPlan::class)->build($f['excel'], $f['csv']);
    expect($plan['valid'])->toBeTrue()->and($plan['counts']['new'])->toBe(0)
        ->and($plan['skip_reasons']['inactive_new_applicability'])->toBe(1)
        ->and($generation->fresh()->is_active)->toBeFalse();
});

test('partial CSV price normalization retains manual SKU descriptions and price history', function (): void {
    $f = safeRunFixture();
    $p = $f['products']['Stable'];
    $historyBefore = DB::table('catalog_matrix_price_history')->where('product_id', $p->id)->count();
    $v = $p->variants()->where('is_default', true)->firstOrFail();
    DB::table('product_variants')->where('id', $v->id)->update(['price' => '123.00', 'price_mode' => 'individual', 'sku' => 'MANUAL-VARIANT-SKU']);
    DB::table('products')->where('id', $p->id)->update(['price' => '123.00', 'price_mode' => 'individual', 'sku' => 'MANUAL-PRODUCT-SKU']);
    $review = app(CatalogSafePartialImport::class)->preview($f['run']);
    expect($review['variant_counts']['variant_prices_change'])->toBe(1)
        ->and($review['variant_counts']['product_prices_change'])->toBe(1);
    $service = safeApprove($f);
    $run = $service->start($f['run']->fresh());
    while ($service->batch($run, $run->fresh()->prepared_rows['last_item_id'])) {
    }
    expect($p->fresh()->sku)->toBe('MANUAL-PRODUCT-SKU')
        ->and($v->fresh()->sku)->toBe('MANUAL-VARIANT-SKU')
        ->and($p->fresh()->description)->toBe('Manual description')
        ->and($v->fresh()->price)->not->toBe('123.00')
        ->and(DB::table('catalog_matrix_price_history')->where('product_id', $p->id)->count())->toBe($historyBefore + 2);
});

test('old vehicle image jobs cannot download media for protected applicability', function (): void {
    $f = safeRunFixture();
    safeApprove($f);
    Http::preventStrayRequests();
    expect(fn () => app(ImportImageDownloader::class)
        ->downloadVehicleGenerationImage($f['generations']['Conflict'], 'https://example.com/car.jpg'))
        ->toThrow(ValidationException::class, 'постоянную защиту');
    Http::assertNothingSent();
});
