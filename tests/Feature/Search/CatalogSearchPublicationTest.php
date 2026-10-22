<?php

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\DownloadProductImageJob;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Jobs\PublishCatalogSearch;
use App\Jobs\RefreshCatalogSearchDependencies;
use App\Models\ImportRun;
use App\Models\Product;
use App\Services\Import\CatalogImportSnapshot;
use App\Services\Import\ImportProductFactory;
use App\Services\ImportStatusService;
use App\Services\Search\CatalogSearchDocument;
use App\Services\Search\CatalogSearchIndexer;
use App\Services\Search\CatalogSearchPublication;
use App\Services\Search\CatalogSearchSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\Jobs\MakeSearchable;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\CommunicationException;

require_once __DIR__.'/../../Support/CatalogSearchFixtures.php';

beforeEach(function (): void {
    if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Publication tests require isolated in-memory SQLite.');
    }
    $this->artisan('migrate:fresh', ['--force' => true]);
    Queue::fake();
});

function preparedSearchPublication(int $images = 0): array
{
    return CatalogSearchSync::withoutSyncing(function () use ($images): array {
        $fixture = searchVehicleFixture();
        $old = $fixture['product'];
        $old->update(['import_source' => 'catalog', 'import_key' => 'catalog:old']);
        $returned = Product::factory()->withDefaultVariant()->create([
            'title' => 'Вернувшаяся деталь', 'status' => ProductStatus::Archived,
        ]);
        // The existing offer predates catalog ownership. Attach source metadata only afterward.
        $returned->forceFill(['import_source' => 'catalog', 'import_key' => 'catalog:returned'])->saveQuietly();
        $run = ImportRun::factory()->create([
            'type' => 'catalog', 'status' => ImportRunStatus::RunningRows, 'errors_count' => 0,
            'processed_rows' => 1, 'current_row' => 1, 'total_rows' => 1, 'queued_images' => $images,
            'prepared_rows' => ['snapshot_version' => 1, 'expected_products' => 1],
        ]);
        DB::table('catalog_import_snapshot_items')->insert([
            'import_run_id' => $run->id, 'import_key' => $returned->import_key, 'product_id' => $returned->id,
        ]);

        return [$run, $old, $returned];
    });
}

function publishSearchSnapshot(ImportRun $run): void
{
    CatalogSearchSync::withoutSyncing(fn () => app(CatalogImportSnapshot::class)->finalize(
        $run, fn () => app(ImportProductFactory::class)->archiveMissingProducts($run),
    ));
}

test('snapshot publication persists one search request after commit irrespective of images', function (int $images): void {
    [$run, $old, $returned] = preparedSearchPublication($images);
    $identity = [$returned->id, $returned->slug, $returned->variants()->pluck('id')->all()];
    config(['scout.driver' => 'meilisearch']);
    DB::beginTransaction();
    publishSearchSnapshot($run);
    Queue::assertNothingPushed();
    expect(DB::table('catalog_search_publications')->count())->toBe(1);
    DB::commit();
    Queue::assertPushed(PublishCatalogSearch::class, 1);
    Queue::assertNotPushed(RefreshCatalogSearchDependencies::class);
    expect($old->refresh()->status)->toBe(ProductStatus::Archived)
        ->and($returned->refresh()->status)->toBe(ProductStatus::Active)
        ->and([$returned->id, $returned->slug, $returned->variants()->pluck('id')->all()])->toBe($identity)
        ->and($run->refresh()->status)->toBe($images ? ImportRunStatus::ProcessingImages : ImportRunStatus::Done);
    app()->call([new CatalogImportChunkJob($run->id), 'handle']);
    if ($images) {
        app(ImportStatusService::class)->imageProcessed($run);
    }
    expect($run->refresh()->status)->toBe(ImportRunStatus::Done)
        ->and(DB::table('catalog_search_publications')->count())->toBe(1);
    Queue::assertPushed(PublishCatalogSearch::class, 1);
    Queue::assertNotPushed(RefreshCatalogSearchDependencies::class);
})->with([0, 1]);

test('rollback discards both assortment publication and durable search request', function (): void {
    [$run, $old, $returned] = preparedSearchPublication();
    config(['scout.driver' => 'meilisearch']);
    DB::beginTransaction();
    publishSearchSnapshot($run);
    DB::rollBack();
    app(CatalogSearchPublication::class)->dispatchPending();
    expect($old->refresh()->status)->toBe(ProductStatus::Active)
        ->and($returned->refresh()->status)->toBe(ProductStatus::Archived)
        ->and(DB::table('catalog_search_publications')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('import errors prevent publishing any search request', function (): void {
    [$run, $old, $returned] = preparedSearchPublication();
    $run->update(['errors_count' => 1]);
    config(['scout.driver' => 'meilisearch']);
    expect(fn () => publishSearchSnapshot($run))->toThrow(RuntimeException::class)
        ->and(DB::table('catalog_search_publications')->count())->toBe(0)
        ->and($old->refresh()->status)->toBe(ProductStatus::Active)
        ->and($returned->refresh()->status)->toBe(ProductStatus::Archived);
    Queue::assertNothingPushed();
});

test('a lost queue message is redispatched from the committed request after its lease', function (): void {
    [$run] = preparedSearchPublication();
    config(['scout.driver' => 'meilisearch']);
    publishSearchSnapshot($run);
    $service = app(CatalogSearchPublication::class);
    $service->dispatchPending();
    Queue::assertPushed(PublishCatalogSearch::class, 1);
    $this->travel(16)->minutes();
    $service->dispatchPending();
    Queue::assertPushed(PublishCatalogSearch::class, 2);
    expect(DB::table('catalog_search_publications')->count())->toBe(1)
        ->and(DB::table('catalog_search_publications')->value('completed_at'))->toBeNull();
});

test('queue outage cannot roll back an already committed catalog and recovery retains the request', function (): void {
    [$run, $old, $returned] = preparedSearchPublication();
    config(['scout.driver' => 'meilisearch']);
    $dispatcher = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));
    publishSearchSnapshot($run);
    Bus::swap($dispatcher);
    expect($run->refresh()->status)->toBe(ImportRunStatus::Done)
        ->and($old->refresh()->status)->toBe(ProductStatus::Archived)
        ->and($returned->refresh()->status)->toBe(ProductStatus::Active)
        ->and(DB::table('catalog_search_publications')->value('completed_at'))->toBeNull();
    $this->travel(16)->minutes();
    app(CatalogSearchPublication::class)->dispatchPending();
    Queue::assertPushed(PublishCatalogSearch::class, 1);
});

test('failed meilisearch task keeps the checkpoint and retry applies documents without restarting the scan', function (): void {
    [$run, $old, $returned] = preparedSearchPublication();
    config(['scout.driver' => 'meilisearch', 'scout.chunk.searchable' => 1]);
    publishSearchSnapshot($run);
    $id = DB::table('catalog_search_publications')->value('id');
    DB::table('catalog_search_publications')->where('id', $id)->update(['model_position' => 3]);
    $documents = [];
    $index = Mockery::mock(Indexes::class);
    $index->shouldReceive('addDocuments')->andReturnUsing(function ($batch) use (&$documents): array {
        foreach ($batch as $document) {
            $documents[$document['id']] = $document;
        }

        return ['taskUid' => 42];
    });
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->andReturn($index);
    $client->shouldReceive('waitForTask')->once()->andReturn(['status' => 'failed', 'error' => ['message' => 'temporary failure']]);
    $client->shouldReceive('waitForTask')->andReturn(['status' => 'succeeded']);
    app()->instance(Client::class, $client);
    $job = new PublishCatalogSearch($id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'temporary failure')
        ->and(DB::table('catalog_search_publications')->value('last_id'))->toBe(0)
        ->and(DB::table('catalog_search_publications')->value('completed_at'))->toBeNull();
    app()->call([$job, 'handle']);
    expect(DB::table('catalog_search_publications')->value('last_id'))->toBe($old->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect(DB::table('catalog_search_publications')->value('completed_at'))->not->toBeNull()
        ->and($documents[$old->id]['is_active'])->toBeFalse()
        ->and($documents[$returned->id]['is_active'])->toBeTrue();
    $before = $documents;
    app()->call([$job, 'handle']);
    expect($documents)->toBe($before);
});

test('real meilisearch publishes archive and reactivation independently of images and excludes inactive hits', function (int $images): void {
    if (getenv('MEILISEARCH_INTEGRATION') !== '1') {
        $this->markTestSkipped('Explicit opt-in: MEILISEARCH_INTEGRATION=1');
    }
    [$run, $old, $returned] = preparedSearchPublication($images);
    $prefix = 'dvashop_publication_test_'.bin2hex(random_bytes(6)).'_';
    config(['scout.driver' => 'meilisearch', 'scout.prefix' => $prefix]);
    $client = app(Client::class);
    $indexer = app(CatalogSearchIndexer::class);
    $index = $client->index($old->indexableAs());
    try {
        $settings = $index->updateFilterableAttributes(['is_active']);
        expect($client->waitForTask($settings['taskUid'], 30000)['status'])->toBe('succeeded');
        $indexer->refreshConfirmed(Product::with(CatalogSearchDocument::relations(new Product))->get());
        expect(array_column($index->search('', ['filter' => 'is_active = true'])->getHits(), 'id'))->toBe([$old->id]);
        publishSearchSnapshot($run);
        $id = DB::table('catalog_search_publications')->value('id');
        app()->instance(Client::class, new Client('http://127.0.0.1:1', null,
            new GuzzleHttp\Client(['connect_timeout' => 0.2, 'timeout' => 0.2])));
        expect(fn () => app()->call([new PublishCatalogSearch($id), 'handle']))
            ->toThrow(CommunicationException::class)
            ->and(DB::table('catalog_search_publications')->value('last_id'))->toBe(0)
            ->and(DB::table('catalog_search_publications')->value('completed_at'))->toBeNull();
        app()->instance(Client::class, $client);
        for ($attempt = 0; $attempt < 20 && DB::table('catalog_search_publications')->value('completed_at') === null; $attempt++) {
            app()->call([new PublishCatalogSearch($id), 'handle']);
        }
        expect(DB::table('catalog_search_publications')->value('completed_at'))->not->toBeNull()
            ->and($run->refresh()->status)->toBe($images ? ImportRunStatus::ProcessingImages : ImportRunStatus::Done)
            ->and(array_column($index->search('', ['filter' => 'is_active = true'])->getHits(), 'id'))->toBe([$returned->id])
            ->and($index->getDocument($old->id)['is_active'])->toBeFalse();
        app()->call([new PublishCatalogSearch($id), 'handle']);
        expect(array_column($index->search('', ['filter' => 'is_active = true'])->getHits(), 'id'))->toBe([$returned->id]);
    } finally {
        foreach (CatalogSearchDocument::MODELS as $class) {
            $uid = (new $class)->indexableAs();
            if (! str_starts_with($uid, $prefix)) {
                throw new RuntimeException('Refusing to delete a non-test index.');
            }
            $task = $client->deleteIndex($uid);
            $client->waitForTask($task['taskUid'], 30000);
        }
    }
})->with([0, 1]);

test('snapshot image jobs save photos without per-image search jobs or another full publication', function (): void {
    fake_vehicle_photo_verifier(smallFixtures: true);
    [$run, $old, $returned] = preparedSearchPublication(2);
    $generation = $old->fitments()->firstOrFail()->generation;
    $url = 'https://images.example.test/publication.jpg';
    Storage::fake('public');
    Http::preventStrayRequests();
    Http::fake([$url => Http::response(test_image_binary(), 200, ['Content-Type' => 'image/jpeg'])]);
    config(['scout.driver' => 'meilisearch', 'scout.queue' => true]);
    app()->call([new DownloadVehicleGenerationImageJob($generation->id, $url, $run->id), 'handle']);
    expect($generation->refresh()->image_source_url)->toBe($url)
        ->and($run->refresh()->processed_images)->toBe(1);
    Queue::assertNothingPushed();
    publishSearchSnapshot($run);
    expect($run->refresh()->status)->toBe(ImportRunStatus::ProcessingImages);
    app()->call([new DownloadProductImageJob($returned->id, $url, $run->id), 'handle']);
    expect($returned->images()->where('source_url', $url)->exists())->toBeTrue()
        ->and($run->refresh()->processed_images)->toBe(2)
        ->and($run->status)->toBe(ImportRunStatus::Done);
    Queue::assertPushed(PublishCatalogSearch::class, 1);
    Queue::assertNotPushed(RefreshCatalogSearchDependencies::class);
    Queue::assertNotPushed(MakeSearchable::class);
});
