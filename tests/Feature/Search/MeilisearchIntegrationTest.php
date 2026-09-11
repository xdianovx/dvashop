<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Services\Search\CatalogSearchDocument;
use App\Services\Search\CatalogSearchIndexer;
use App\Services\Search\CatalogSearchSync;
use App\Services\Search\DatabaseCatalogSearchProvider;
use App\Services\Search\MeilisearchCatalogSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

require_once __DIR__.'/../../Support/CatalogSearchFixtures.php';

uses(RefreshDatabase::class);

test('real meilisearch returns exact RU EN mixed prefix short code sku and multi fitment identities', function (): void {
    if (getenv('MEILISEARCH_INTEGRATION') !== '1') {
        $this->markTestSkipped('Explicit opt-in: MEILISEARCH_INTEGRATION=1');
    }
    $prefix = 'dvashop_test_'.bin2hex(random_bytes(6)).'_';
    config(['scout.prefix' => $prefix, 'scout.driver' => 'meilisearch', 'scout.queue' => false, 'catalog-search.driver' => 'meilisearch', 'catalog-search.timeout_ms' => 1000]);
    Queue::fake();
    $client = app(Client::class);
    expect($client->health()['status'])->toBe('available');
    $wait = function () use ($client): void {
        foreach (CatalogSearchDocument::MODELS as $class) {
            $tasks = $client->getTasks((new TasksQuery)->setIndexUids([(new $class)->searchableAs()])->setLimit(1))->getResults();
            if ($tasks !== []) {
                $task = $client->waitForTask($tasks[0]['uid'], 30000, 20);
                expect($task['status'])->toBe('succeeded', json_encode($task['error'] ?? []));
            }
        }
    };
    try {
        $fixtures = CatalogSearchSync::withoutSyncing(function (): array {
            $fixtures = [];
            foreach ([
                ['Audi', '100', [], []],
                ['Toyota', 'Camry', [], []],
                ['Subaru', 'Legacy', [], []],
                ['BMW', 'X5', [], []],
                ['Mercedes', 'C3', [], []],
                ['Chevrolet', 'CX-5', ['шевроле'], []],
            ] as [$make, $model, $aliases, $modelAliases]) {
                $fixtures[$make] = searchVehicleFixture($make, $model, $aliases, $modelAliases);
                $fixtures[$make]['product']->update(['title' => 'Кузовной комплект '.$fixtures[$make]['product']->id]);
            }
            $fixtures['Toyota']['generation']->update(['years_label' => '2012–2015']);
            foreach ([['Wish', 'XV'], ['Vision', 'V1'], ['Vista', 'V2']] as [$title, $generationTitle]) {
                $model = VehicleModel::factory()->forMake($fixtures['Toyota']['make'])->create(['title' => $title, 'search_aliases' => null]);
                $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => $generationTitle]);
                $product = Product::factory()->withDefaultVariant()->create(['title' => 'Кузовной комплект '.$title]);
                ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
                $fixtures[$title] = compact('model', 'generation', 'product');
            }
            foreach (['A3', 'A4', 'Q5', 'Q7', 'X3'] as $code) {
                $model = VehicleModel::factory()->forMake($fixtures['Audi']['make'])->create(['title' => $code]);
                $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
                $product = Product::factory()->withDefaultVariant()->create();
                ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
                $fixtures[$code] = compact('model', 'generation', 'product');
            }
            searchHiddenVariantFixture($fixtures['Toyota']['product'], 'HIDDEN-VALUE-SKU', false);
            searchHiddenVariantFixture($fixtures['Toyota']['product'], 'HIDDEN-GROUP-SKU', true);

            $proboxModel = VehicleModel::factory()->forMake($fixtures['Toyota']['make'])->create(['title' => 'Probox', 'search_aliases' => null]);
            $proboxGeneration = VehicleGeneration::factory()->forVehicleModel($proboxModel)->create(['title' => 'P1']);
            $proboxProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Арка внутренняя универсальная']);
            ProductFitment::factory()->forProduct($proboxProduct)->forVehicleGeneration($proboxGeneration)->create();
            $proboxOtherProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Порог левый']);
            ProductFitment::factory()->forProduct($proboxOtherProduct)->forVehicleGeneration($proboxGeneration)->create();
            $fixtures['Probox'] = ['model' => $proboxModel, 'generation' => $proboxGeneration, 'product' => $proboxProduct, 'other_product' => $proboxOtherProduct];

            $landCruiserModel = VehicleModel::factory()->forMake($fixtures['Toyota']['make'])->create(['title' => 'Land Cruiser', 'search_aliases' => null]);
            $landCruiserGeneration = VehicleGeneration::factory()->forVehicleModel($landCruiserModel)->create(['title' => 'LC']);
            $landCruiserProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Арка задняя']);
            ProductFitment::factory()->forProduct($landCruiserProduct)->forVehicleGeneration($landCruiserGeneration)->create();
            $fixtures['Land Cruiser'] = ['model' => $landCruiserModel, 'generation' => $landCruiserGeneration, 'product' => $landCruiserProduct];

            $hondaPartner = searchVehicleFixture('Honda', 'Partner', [], []);
            $hondaPartner['product']->update(['title' => 'Порог левый']);
            $peugeotPartner = searchVehicleFixture('Peugeot', 'Partner', [], []);
            $peugeotPartner['product']->update(['title' => 'Порог правый']);
            $fixtures['Honda Partner'] = $hondaPartner;
            $fixtures['Peugeot Partner'] = $peugeotPartner;

            $kalosModel = VehicleModel::factory()->forMake($fixtures['Toyota']['make'])->create(['title' => 'Kalos', 'search_aliases' => ['калос']]);
            $kalosGeneration = VehicleGeneration::factory()->forVehicleModel($kalosModel)->create();
            $kalosProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Порог Kalos']);
            ProductFitment::factory()->forProduct($kalosProduct)->forVehicleGeneration($kalosGeneration)->create();
            $koleosModel = VehicleModel::factory()->forMake($fixtures['Toyota']['make'])->create(['title' => 'Koleos', 'search_aliases' => null]);
            $koleosGeneration = VehicleGeneration::factory()->forVehicleModel($koleosModel)->create();
            $koleosProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Порог Koleos']);
            ProductFitment::factory()->forProduct($koleosProduct)->forVehicleGeneration($koleosGeneration)->create();
            $fixtures['Kalos'] = ['model' => $kalosModel, 'generation' => $kalosGeneration, 'product' => $kalosProduct];
            $fixtures['Koleos'] = ['model' => $koleosModel, 'generation' => $koleosGeneration, 'product' => $koleosProduct];

            $publicCamryProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Арка public fitment']);
            ProductFitment::factory()->forProduct($publicCamryProduct)->forVehicleGeneration($fixtures['Toyota']['generation'])->create();
            $staleCamryGeneration = VehicleGeneration::factory()->forVehicleModel($fixtures['Toyota']['model'])->create(['title' => 'STALE-CAMRY']);
            $staleCamryProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Арка stale fitment']);
            ProductFitment::factory()->forProduct($staleCamryProduct)->forVehicleGeneration($staleCamryGeneration)->create();
            $fixtures['Camry Fitment Visibility'] = ['public_product' => $publicCamryProduct, 'stale_generation' => $staleCamryGeneration, 'stale_product' => $staleCamryProduct];

            foreach (['Toyota', 'BMW'] as $make) {
                ProductFitment::factory()->forProduct($fixtures['Audi']['product'])->forVehicleGeneration($fixtures[$make]['generation'])->create();
            }

            for ($i = 0; $i < 50; $i++) {
                VehicleModel::factory()->forMake($fixtures['Toyota']['make'])->create([
                    'title' => sprintf('RefillTarget Hidden %02d', $i),
                    'position' => $i,
                ]);
            }
            $refillModel = VehicleModel::factory()->forMake($fixtures['Toyota']['make'])->create([
                'title' => 'RefillTarget Public',
                'position' => 100,
            ]);
            $refillGeneration = VehicleGeneration::factory()->forVehicleModel($refillModel)->create();
            $refillProduct = Product::factory()->withDefaultVariant()->create(['title' => 'RefillTarget Product']);
            ProductFitment::factory()->forProduct($refillProduct)->forVehicleGeneration($refillGeneration)->create();
            $fixtures['RefillTarget'] = ['model' => $refillModel, 'generation' => $refillGeneration, 'product' => $refillProduct];

            return $fixtures;
        });
        Artisan::call('scout:sync-index-settings', ['--driver' => 'meilisearch']);
        $wait();
        foreach (CatalogSearchDocument::MODELS as $class) {
            app(CatalogSearchIndexer::class)->refresh($class::query());
        }
        $wait();
        $provider = app(MeilisearchCatalogSearchProvider::class);
        $matrix = [
            ['Audi', 'makes', 'Audi', 'make'], ['ауди', 'makes', 'Audi', 'make'], ['ауд', 'makes', 'Audi', 'make'],
            ['Audi 100', 'models', 'Audi', 'model'], ['ауди 100', 'models', 'Audi', 'model'],
            ['Toyota', 'makes', 'Toyota', 'make'], ['тойота', 'makes', 'Toyota', 'make'], ['njqjnf', 'makes', 'Toyota', 'make'],
            ['Camry', 'models', 'Toyota', 'model'], ['камри', 'models', 'Toyota', 'model'], ['камр', 'models', 'Toyota', 'model'],
            ['сфькн', 'models', 'Toyota', 'model'], ['rfvhb', 'models', 'Toyota', 'model'],
            ['Wish', 'models', 'Wish', 'model'], ['виш', 'models', 'Wish', 'model'],
            ['Legacy', 'models', 'Subaru', 'model'], ['легаси', 'models', 'Subaru', 'model'],
            ['Toyota Camry', 'models', 'Toyota', 'model'], ['тойота камри', 'models', 'Toyota', 'model'],
            ['Toyota камри', 'models', 'Toyota', 'model'], ['тойота Camry', 'models', 'Toyota', 'model'],
            ['BMW X5', 'models', 'BMW', 'model'], ['бмв X5', 'models', 'BMW', 'model'],
            ['Mercedes', 'makes', 'Mercedes', 'make'], ['мерседес', 'makes', 'Mercedes', 'make'],
            ['Chevrolet', 'makes', 'Chevrolet', 'make'], ['шевроле', 'makes', 'Chevrolet', 'make'],
        ];
        foreach (['A3', 'A4', 'Q5', 'Q7', 'X3'] as $code) {
            $matrix[] = [$code, 'models', $code, 'model'];
        }
        $matrix[] = ['X5', 'models', 'BMW', 'model'];
        $matrix[] = ['C3', 'models', 'Mercedes', 'model'];
        $matrix[] = ['CX-5', 'models', 'Chevrolet', 'model'];
        foreach ($matrix as [$query, $group, $fixture, $field]) {
            $ids = $provider->candidates($query)['ids'][$group];
            expect($ids[0] ?? null)->toBe($fixtures[$fixture][$field]->id, $query.' must rank the exact expected identity first');
        }
        expect($provider->candidates('виш')['ids']['models'])->toContain($fixtures['Wish']['model']->id)
            ->and($provider->candidates('виш')['ids']['models'])->not->toContain($fixtures['Vision']['model']->id, $fixtures['Vista']['model']->id)
            ->and($provider->candidates('ви')['ids']['models'])->toBe([])
            ->and($provider->candidates('камри')['ids']['models'][0] ?? null)->toBe($fixtures['Toyota']['model']->id)
            ->and($provider->candidates('виш')['ids']['products'])->toContain($fixtures['Wish']['product']->id)
            ->and($provider->candidates('A3')['ids']['models'])->not->toContain($fixtures['A4']['model']->id)
            ->and($provider->candidates('Q5')['ids']['models'])->not->toContain($fixtures['Q7']['model']->id)
            ->and($provider->candidates('X5')['ids']['models'])->not->toContain($fixtures['X3']['model']->id);
        foreach (['тойота камри', 'Toyota камри', 'бмв X5', 'ауди 100', 'камри 2012', 'тойота камри 2015', 'rfvhb'] as $query) {
            expect($provider->candidates($query)['ids']['products'])->toContain($fixtures['Audi']['product']->id);
        }

        foreach (['арка toyota probox', 'арка тойота пробокс', 'арка Toyota пробокс', 'арка тойота Probox'] as $query) {
            $ids = $provider->search($query)['products']->getCollection()->pluck('id')->all();
            expect($ids)->toContain($fixtures['Probox']['product']->id)
                ->and($ids)->not->toContain($fixtures['Probox']['other_product']->id);
        }
        foreach (['toyota probox', 'тойота пробокс'] as $query) {
            expect($provider->search($query)['products']->getCollection()->pluck('id')->all())
                ->toContain($fixtures['Probox']['product']->id);
        }
        expect($provider->search('арка ленд крузер')['products']->getCollection()->pluck('id')->all())
            ->toContain($fixtures['Land Cruiser']['product']->id);

        foreach ([
            ['порог Honda Partner', 'Honda Partner', 'Peugeot Partner'],
            ['порог хонда партнер', 'Honda Partner', 'Peugeot Partner'],
            ['порог Peugeot Partner', 'Peugeot Partner', 'Honda Partner'],
        ] as [$query, $expected, $unexpected]) {
            $ids = $provider->search($query)['products']->getCollection()->pluck('id')->all();
            expect($ids)->toContain($fixtures[$expected]['product']->id)
                ->and($ids)->not->toContain($fixtures[$unexpected]['product']->id);
        }
        $partnerWithoutMake = $provider->explain('порог Partner')['product_query_plan'];
        expect($partnerWithoutMake['product_filters']['model_id'] ?? null)->toBeNull()
            ->and($partnerWithoutMake['consumed_tokens'])->not->toContain('partner')
            ->and(collect($partnerWithoutMake['rejections'])->contains(fn (array $rejection): bool => ($rejection['reason'] ?? null) === 'ambiguous_after_hierarchy'))->toBeTrue();

        $kalosPlan = $provider->explain('порог калос')['product_query_plan'];
        $kalosPhrase = collect($kalosPlan['candidate_phrases'])->firstWhere('phrase', 'калос');
        expect($kalosPlan['product_filters']['model_id'] ?? null)->toBe($fixtures['Kalos']['model']->id)
            ->and($kalosPlan['product_filters']['model_id'] ?? null)->not->toBe($fixtures['Koleos']['model']->id)
            ->and($kalosPhrase['channel_priority']['models']['selected_tier'] ?? null)->toBe('strong')
            ->and($kalosPhrase['channel_priority']['models']['ignored_lower_tiers'] ?? [])->toContain('phonetic');

        $explain = $provider->explain('арка тойота пробокс');
        expect($explain['product_query_plan']['active'])->toBeTrue()
            ->and($explain['product_query_plan']['residual_product_terms'])->toBe(['арка'])
            ->and($explain['product_query_plan']['product_filters']['make_id'] ?? null)->toBe($fixtures['Toyota']['make']->id)
            ->and($explain['product_query_plan']['product_filters']['model_id'] ?? null)->toBe($fixtures['Probox']['model']->id);

        expect($provider->search('RefillTarget')['models']->pluck('model_title')->all())
            ->toContain('RefillTarget Public');
        foreach ([$fixtures['Toyota']['product']->title, $fixtures['Toyota']['product']->sku, $fixtures['Toyota']['variant']->sku] as $query) {
            expect($provider->candidates($query)['ids']['products'][0] ?? null)->toBe($fixtures['Toyota']['product']->id);
        }
        expect($provider->candidates('NeverIndexThisVariantName')['ids']['products'])->toBe([]);
        foreach (['HIDDEN-VALUE-SKU', 'HIDDEN-GROUP-SKU'] as $sku) {
            expect($provider->candidates($sku)['ids']['products'])->toBe([])
                ->and(app(DatabaseCatalogSearchProvider::class)->search($sku)['products'])->toBeEmpty();
            $this->get(route('catalog.index', ['q' => $sku]))->assertOk()->assertDontSee($fixtures['Toyota']['product']->title);
        }

        // Product entity filters must also validate the exact matching fitment chain in MySQL.
        CatalogSearchSync::withoutSyncing(fn () => $fixtures['Camry Fitment Visibility']['stale_generation']->update(['is_active' => false]));
        $camryFitmentIds = $provider->search('арка Camry')['products']->getCollection()->pluck('id')->all();
        expect($camryFitmentIds)->toContain($fixtures['Camry Fitment Visibility']['public_product']->id)
            ->and($camryFitmentIds)->not->toContain($fixtures['Camry Fitment Visibility']['stale_product']->id);

        // Product entity planning must validate stale vehicle hits in MySQL before consuming them.
        CatalogSearchSync::withoutSyncing(fn () => $fixtures['Honda Partner']['model']->update(['is_active' => false]));
        $stalePlan = $provider->explain('порог Honda Partner')['product_query_plan'];
        expect($stalePlan['product_filters']['model_id'] ?? null)->toBeNull()
            ->and($stalePlan['consumed_tokens'])->not->toContain('partner')
            ->and(collect($stalePlan['rejections'])->contains(fn (array $rejection): bool => ($rejection['reason'] ?? null) === 'not_public'))->toBeTrue();

        // Public rendering still checks current SQL visibility even with a stale document.
        CatalogSearchSync::withoutSyncing(fn () => $fixtures['Toyota']['product']->update(['status' => ProductStatus::Archived]));
        $this->get(route('catalog.index', ['q' => $fixtures['Toyota']['product']->sku]))->assertOk()->assertDontSee($fixtures['Toyota']['product']->title);
        $this->get(route('catalog.index', ['q' => 'бмв X5']))->assertOk()->assertSee('BMW')->assertSee('X5');
    } finally {
        foreach (CatalogSearchDocument::MODELS as $class) {
            $task = $client->deleteIndex((new $class)->searchableAs());
            $client->waitForTask($task['taskUid'], 30000, 20);
        }
    }
})->group('meilisearch-integration');
