<?php

use App\Enums\ImportRunStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\RefreshCatalogSearchDependencies;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\CatalogSearchIndexer;
use App\Services\Search\CatalogSearchSync;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Jobs\MakeSearchable;

require_once __DIR__.'/../../Support/CatalogSearchFixtures.php';

uses(RefreshDatabase::class);

test('dependency updates and scout sync wait for commit and rollback discards both', function (): void {
    $fixture = searchVehicleFixture();
    Queue::fake();
    config(['scout.driver' => 'meilisearch', 'scout.queue' => ['connection' => 'redis', 'queue' => 'catalog-search']]);
    DB::beginTransaction();
    $fixture['make']->update(['search_aliases' => ['новое имя']]);
    Queue::assertNothingPushed();
    DB::rollBack();
    Queue::assertNothingPushed();
    DB::beginTransaction();
    $fixture['make']->refresh()->update(['search_aliases' => ['другое имя']]);
    Queue::assertNothingPushed();
    DB::commit();
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn ($job) => $job->source === VehicleMake::class && $job->ids === [$fixture['make']->id]);
    Queue::assertPushed(MakeSearchable::class);
});

test('variant and fitment changes refresh parent products after commit', function (): void {
    $fixture = searchVehicleFixture();
    Queue::fake();
    config(['scout.driver' => 'meilisearch', 'scout.queue' => true]);
    $fixture['variant']->update(['sku' => 'NEW-SKU']);
    $fixture['product']->fitments()->first()->delete();
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, 2);
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn ($job) => $job->source === Product::class && $job->ids === [$fixture['product']->id]);
});

test('failed or canceled imports do not refresh and a successful terminal transition refreshes once', function (): void {
    Queue::fake();
    config(['scout.driver' => 'meilisearch']);
    foreach ([ImportRunStatus::Failed, ImportRunStatus::Canceled] as $status) {
        $run = ImportRun::factory()->create(['type' => 'catalog', 'status' => ImportRunStatus::RunningRows]);
        $run->update(['status' => $status]);
    }
    Queue::assertNothingPushed();
    $run = ImportRun::factory()->create(['type' => 'catalog', 'status' => ImportRunStatus::RunningRows, 'errors_count' => 0]);
    $run->update(['status' => ImportRunStatus::Done]);
    $run->update(['heartbeat_at' => now()]);
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, 1);
});

test('real import chunk suppresses row jobs and preserves manual aliases on repeat import', function (): void {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(ProductOptionSeeder::class);
    Storage::fake('local');
    Storage::disk('local')->put('imports/search.csv', ";;;;;;Порог\n;Марка;Модель;Поколение;Годы;Кузов;Порог\n;Toyota;Camry;XV70;2017-2023;седан;1\n");
    Queue::fake();
    config(['scout.driver' => 'meilisearch', 'scout.queue' => ['connection' => 'redis', 'queue' => 'catalog-search']]);
    $state = [
        'type' => 'catalog', 'status' => ImportRunStatus::RunningRows, 'stored_path' => 'imports/search.csv',
        'total_rows' => 1, 'current_row' => 0, 'processed_rows' => 0, 'chunk_size' => 10, 'errors_count' => 0,
        'detail_columns' => [6 => ['index' => 6, 'group' => null, 'parent_title' => null, 'title' => 'Порог', 'detail_title' => 'Порог', 'full_detail_title' => 'Порог', 'category_title' => 'Порог']],
    ];
    $run = ImportRun::factory()->create($state);
    app()->call([new CatalogImportChunkJob($run->id), 'handle']);
    expect($run->fresh()->errors_count)->toBe(0);
    $make = VehicleMake::where('title', 'Toyota')->firstOrFail();
    $model = $make->models()->firstOrFail();
    CatalogSearchSync::withoutSyncing(function () use ($make, $model): void {
        $make->update(['search_aliases' => ['тойота']]);
        $model->update(['search_aliases' => ['камри']]);
    });
    $again = ImportRun::factory()->create($state);
    app()->call([new CatalogImportChunkJob($again->id), 'handle']);
    expect($again->fresh()->errors_count)->toBe(0)
        ->and($make->fresh()->search_aliases)->toBe(['тойота'])
        ->and($model->fresh()->search_aliases)->toBe(['камри']);
    Queue::assertNotPushed(MakeSearchable::class);
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, 2);
});

test('batched dependency job rebuilds inherited data without jobs per descendant', function (): void {
    $fixture = searchVehicleFixture();
    $fixture['make']->update(['search_aliases' => ['новая марка']]);
    $fixture['model']->update(['search_aliases' => ['новая модель']]);
    Queue::fake();
    config(['scout.driver' => 'meilisearch']);
    $documents = [];
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('update')->andReturnUsing(function ($models) use (&$documents): void {
        foreach ($models as $model) {
            $documents[$model::class][$model->id] = $model->toSearchableArray();
        }
    });
    app(EngineManager::class)->extend('meilisearch', fn () => $engine);
    (new RefreshCatalogSearchDependencies(VehicleMake::class, [$fixture['make']->id]))->handle(app(CatalogSearchIndexer::class));
    expect($documents[VehicleModel::class][$fixture['model']->id]['make_aliases'])->toBe(['новая марка'])
        ->and($documents[VehicleGeneration::class][$fixture['generation']->id]['model_aliases'])->toBe(['новая модель'])
        ->and($documents[Product::class][$fixture['product']->id]['make_aliases'])->toBe(['новая марка'])
        ->and($documents[Product::class][$fixture['product']->id]['model_aliases'])->toBe(['новая модель']);
    Queue::assertNothingPushed();
});

test('moving a fitment refreshes both old and new parents and rollbacks do not dispatch', function (): void {
    $fixture = searchVehicleFixture();
    $target = Product::factory()->withDefaultVariant()->create();
    Queue::fake();
    config(['scout.driver' => 'meilisearch']);
    $fitment = $fixture['product']->fitments()->firstOrFail();
    DB::beginTransaction();
    $fitment->update(['product_id' => $target->id]);
    Queue::assertNothingPushed();
    DB::commit();
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn ($job) => $job->source === Product::class && count($job->ids) === 2 && in_array($target->id, $job->ids) && in_array($fixture['product']->id, $job->ids));
});

test('search queue lease exceeds worker timeout without changing default or feed connections', function (): void {
    expect(config('queue.connections.catalog-search.retry_after'))->toBeGreaterThan((new RefreshCatalogSearchDependencies)->timeout)
        ->and(config('queue.connections.catalog-search.after_commit'))->toBeTrue()
        ->and(config('queue.connections.database.retry_after'))->toBe(660)
        ->and(config('queue.connections.yandex-feed.retry_after'))->toBe(1500);
});

test('option visibility changes schedule one batched refresh for affected products', function (): void {
    $fixture = searchVehicleFixture();
    $hidden = searchHiddenVariantFixture($fixture['product'], 'OPTION-BOUND-SKU', false);
    $value = $hidden->optionValues()->firstOrFail();
    Queue::fake();
    config(['scout.driver' => 'meilisearch']);
    $value->update(['is_active' => true]);
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, 1);
    Queue::assertPushed(RefreshCatalogSearchDependencies::class, fn ($job) => $job->source === ProductOptionValue::class && $job->ids === [$value->id]);
    $documents = [];
    $engine = Mockery::mock(Engine::class);
    $engine->shouldReceive('update')->once()->andReturnUsing(function ($models) use (&$documents): void {
        $documents = $models->map->toSearchableArray()->all();
    });
    app(EngineManager::class)->extend('meilisearch', fn () => $engine);
    (new RefreshCatalogSearchDependencies(ProductOptionValue::class, [$value->id]))->handle(app(CatalogSearchIndexer::class));
    expect($documents[0]['variant_skus'])->toContain('OPTION-BOUND-SKU');
});
