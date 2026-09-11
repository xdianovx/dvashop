<?php

use App\Enums\HomepageSectionCode;
use App\Enums\ProductStatus;
use App\Filament\Resources\VehicleMakes\Pages\EditVehicleMake;
use App\Filament\Resources\VehicleModels\Pages\EditVehicleModel;
use App\Models\HomepageSection;
use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\CatalogProductQueryPlanner;
use App\Services\Search\CatalogSearchDocument;
use App\Services\Search\CatalogSearchService;
use App\Services\Search\DatabaseCatalogSearchProvider;
use App\Services\Search\MeilisearchCatalogSearchProvider;
use App\Services\Search\SearchText;
use Filament\Facades\Filament;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\InvalidResponseBodyException;

require_once __DIR__.'/../../Support/CatalogSearchFixtures.php';

uses(RefreshDatabase::class);

test('aliases normalize without changing canonical identity and reject invalid values', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Toyota']);
    $identity = $make->only(['title', 'slug', 'norm_key']);
    $make->update(['search_aliases' => [' тойота ', '', 'ТОЙОТА', 'Toyota RU']]);
    expect($make->fresh()->search_aliases)->toBe(['тойота', 'Toyota RU'])
        ->and($make->fresh()->only(array_keys($identity)))->toBe($identity);
    foreach ([['<b>html</b>'], [str_repeat('я', 101)], range(1, 21)] as $invalid) {
        expect(fn () => $make->update(['search_aliases' => $invalid]))->toThrow(ValidationException::class);
    }
    expect(SearchText::transliterate('Тойота Camry'))->toBe('tojota camry');
});

test('search documents include every fitment and active variant sku without variant names or descriptions', function (): void {
    $first = searchVehicleFixture();
    $second = searchVehicleFixture('Audi', 'A3', ['ауди'], []);
    $third = searchVehicleFixture('BMW', 'X5', ['бмв'], []);
    foreach ([$second, $third] as $fixture) {
        ProductFitment::factory()->forProduct($first['product'])->forVehicleGeneration($fixture['generation'])->create();
    }
    ProductVariant::factory()->forProduct($first['product'])->inactive()->create(['sku' => 'HIDDEN-SKU']);
    $product = Product::query()->with(CatalogSearchDocument::relations(new Product))->findOrFail($first['product']->id);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $document = $product->toSearchableArray();
    expect(DB::getQueryLog())->toBe([])
        ->and($document['make_titles'])->toEqualCanonicalizing(['Toyota', 'Audi', 'BMW'])
        ->and($document['make_aliases'])->toEqualCanonicalizing(['тойота', 'ауди', 'бмв'])
        ->and($document['generation_ids'])->toHaveCount(3)
        ->and($document['variant_skus'])->toBe([$first['variant']->sku])
        ->and(json_encode($document))->not->toContain('NeverIndexThisVariantName', 'HIDDEN-SKU', 'description', 'option_values');
    DB::disableQueryLog();
});

test('index preparation has bounded query count for one versus fifty products', function (): void {
    $fixture = searchVehicleFixture();
    Product::factory()->count(49)->withDefaultVariant()->create();
    $counts = [];
    foreach ([1, 50] as $limit) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $models = Product::query()->limit($limit)->get();
        (new Product)->makeSearchableUsing($models);
        foreach ($models as $model) {
            $model->toSearchableArray();
        }
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts[1])->toBeLessThanOrEqual($counts[0] + 1);
});

test('model and generation index preparation stays bounded for one versus fifty records', function (): void {
    $fixture = searchVehicleFixture();
    VehicleModel::factory()->count(49)->forMake($fixture['make'])->create();
    foreach ([VehicleModel::class, VehicleGeneration::class] as $class) {
        if ($class === VehicleGeneration::class) {
            $class::factory()->count(49)->forVehicleModel($fixture['model'])->create();
        }
        $counts = [];
        foreach ([1, 50] as $limit) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $models = $class::query()->limit($limit)->get();
            (new $class)->makeSearchableUsing($models);
            $models->each(fn ($model) => $model->toSearchableArray());
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        expect($counts[1])->toBe($counts[0]);
    }
});

test('rehydration filters stale hits and fills the visible vehicle limit in relevance order', function (): void {
    $fixture = searchVehicleFixture();
    $hidden = VehicleMake::factory()->inactive()->create();
    $ids = ['makes' => [$hidden->id, 999999, $fixture['make']->id], 'models' => [$fixture['model']->id], 'generations' => [$fixture['generation']->id]];
    $result = app(DatabaseCatalogSearchProvider::class)->searchVehicleItems('unrelated text', $ids);
    expect($result['makes']->pluck('title')->all())->toBe(['Toyota']);
    $fixture['product']->update(['status' => ProductStatus::Archived]);
    $result = app(DatabaseCatalogSearchProvider::class)->searchVehicleItems('Toyota', $ids);
    expect($result['makes'])->toBeEmpty()->and($result['models'])->toBeEmpty()->and($result['generations'])->toBeEmpty();
});

test('public hydration query count remains bounded for five versus fifty hits', function (): void {
    $fixture = searchVehicleFixture();
    $products = Product::factory()->count(49)->withDefaultVariant()->create();
    $ids = $products->prepend($fixture['product'])->pluck('id')->all();
    $counts = [];
    foreach ([5, 50] as $limit) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(DatabaseCatalogSearchProvider::class)->hydrateProducts(array_reverse(array_slice($ids, 0, $limit)));
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($result->count())->toBe(min(12, $limit));
    }
    expect($counts[1])->toBeLessThanOrEqual($counts[0] + 1);
});

test('one official multi search request preserves original channel ahead of strict enrichment', function (): void {
    $history = [];
    $results = array_fill(0, 12, ['hits' => [], 'estimatedTotalHits' => 0]);
    $results[0]['hits'] = [['id' => 2, 'title' => 'Other', 'search_aliases' => ['тойота']], ['id' => 1, 'title' => 'тойота']];
    $results[4]['hits'] = [['id' => 3, 'title' => 'Toyota', 'strict_transliteration_terms' => ['toyota']], ['id' => 1, 'title' => 'тойота']];
    $results[8]['hits'] = [['id' => 4, 'title' => 'Toyota', 'vehicle_entities' => [[
        'make_id' => 4, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => null, 'model_title' => null, 'model_aliases' => [],
    ]]]];
    $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results]))]));
    $stack->push(Middleware::history($history));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->once()->andReturn($client);
    expect($provider->candidates('тойота')['ids']['makes'])->toBe([1, 2, 3, 4])
        ->and($history)->toHaveCount(1);
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    expect($payload['queries'])->toHaveCount(12)
        ->and($payload['queries'][0]['matchingStrategy'])->toBe('all')
        ->and($payload['queries'][0]['limit'])->toBe(50)
        ->and($payload['queries'][4]['attributesToSearchOn'])->toBe(['strict_transliteration_terms'])
        ->and($payload['queries'][8]['attributesToSearchOn'])->toBe(['phonetic_terms', 'structured_terms']);
});

test('expected search outages return database results while programming errors propagate', function (): void {
    $fixture = searchVehicleFixture();
    config(['catalog-search.driver' => 'meilisearch']);
    Log::spy();
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class);
    $provider->shouldReceive('search')->once()->andThrow(new CommunicationException('secret credential must not be logged'));
    $this->app->instance(MeilisearchCatalogSearchProvider::class, $provider);
    $this->get(route('catalog.index', ['q' => 'Toyota']))->assertOk()->assertSee($fixture['product']->title);
    Log::shouldHaveReceived('warning')->with('Catalog search unavailable; using database search.', ['exception' => CommunicationException::class, 'status' => null])->once();
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class);
    $provider->shouldReceive('search')->once()->andThrow(new TypeError('application bug'));
    $this->app->instance(MeilisearchCatalogSearchProvider::class, $provider);
    expect(fn () => app(CatalogSearchService::class)->search('Toyota'))->toThrow(TypeError::class);
});

test('homepage and dependent model choices carry aliases with canonical labels', function (): void {
    HomepageSection::factory()->create(['code' => HomepageSectionCode::VehicleSearch, 'is_active' => true]);
    $fixture = searchVehicleFixture();
    $this->get('/')->assertOk()->assertSee('data-custom-properties', false)->assertSee('тойота')->assertSee('Toyota');
    $this->get(route('storefront.vehicle-makes.models', $fixture['make']->slug))->assertOk()
        ->assertJsonPath('0.title', 'Camry')->assertJsonPath('0.slug', $fixture['model']->slug)
        ->assertJsonPath('0.search_aliases.0', 'камри');
    $script = file_get_contents(resource_path('js/app.js'));
    expect($script)->toContain("searchFields: ['label', 'customProperties.searchText']", "customProperties: { searchText: (item.search_aliases || []).join(' ') }");
});

test('aliases migration is reversible and retains canonical vehicle data', function (): void {
    $fixture = searchVehicleFixture();
    $migration = require database_path('migrations/2026_09_09_000100_add_search_aliases_to_vehicles.php');
    $identity = $fixture['make']->only(['title', 'slug', 'norm_key']);
    $migration->down();
    expect(VehicleMake::find($fixture['make']->id)->only(array_keys($identity)))->toBe($identity);
    $migration->up();
    expect(VehicleMake::find($fixture['make']->id)->search_aliases)->toBeNull()
        ->and(VehicleMake::find($fixture['make']->id)->only(array_keys($identity)))->toBe($identity);
});

test('vehicle admin forms save optional aliases and return field validation feedback', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $fixture = searchVehicleFixture();
    foreach ([
        [EditVehicleMake::class, $fixture['make']],
        [EditVehicleModel::class, $fixture['model']],
    ] as [$page, $record]) {
        $identity = $record->only(['title', 'slug', 'norm_key']);
        Livewire::test($page, ['record' => $record->id])
            ->assertFormFieldExists('search_aliases')
            ->fillForm(['search_aliases' => [' новое имя ', 'НОВОЕ ИМЯ']])
            ->call('save')->assertHasNoFormErrors();
        expect($record->fresh()->search_aliases)->toBe(['новое имя'])
            ->and($record->fresh()->only(array_keys($identity)))->toBe($identity);
        Livewire::test($page, ['record' => $record->id])
            ->fillForm(['search_aliases' => ['<script>bad</script>']])
            ->call('save')->assertHasFormErrors();
    }
});

test('server errors and missing indexes fall back but malformed search requests remain visible', function (): void {
    $fixture = searchVehicleFixture();
    config(['catalog-search.driver' => 'meilisearch']);
    foreach ([
        new ApiException(new Response(503), ['code' => 'service_unavailable']),
        new InvalidResponseBodyException(new Response(502), '<html>Bad gateway</html>'),
        new ApiException(new Response(400), ['code' => 'index_not_found']),
    ] as $exception) {
        $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class);
        $provider->shouldReceive('search')->once()->andThrow($exception);
        $this->app->instance(MeilisearchCatalogSearchProvider::class, $provider);
        expect(app(CatalogSearchService::class)->search('Toyota')['makes'])->toHaveCount(1);
    }
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class);
    $provider->shouldReceive('search')->once()->andThrow(new ApiException(new Response(400), ['code' => 'invalid_search_filter']));
    $this->app->instance(MeilisearchCatalogSearchProvider::class, $provider);
    expect(fn () => app(CatalogSearchService::class)->search('Toyota'))->toThrow(ApiException::class);
});

test('search document variant skus follow legacy public option visibility', function (): void {
    $fixture = searchVehicleFixture();
    searchHiddenVariantFixture($fixture['product'], 'HIDDEN-VALUE-SKU', false);
    searchHiddenVariantFixture($fixture['product'], 'HIDDEN-GROUP-SKU', true);
    $record = Product::query()->with(CatalogSearchDocument::relations(new Product))->findOrFail($fixture['product']->id);
    expect($record->toSearchableArray()['variant_skus'])->toBe([$fixture['variant']->sku]);
    foreach (['HIDDEN-VALUE-SKU', 'HIDDEN-GROUP-SKU'] as $sku) {
        expect(app(DatabaseCatalogSearchProvider::class)->search($sku)['products'])->toBeEmpty();
    }
    expect(app(DatabaseCatalogSearchProvider::class)->search($fixture['variant']->sku)['products'])->toHaveCount(1);
});

test('keyboard layout alternatives enter the same bounded strict and phonetic provider pipeline', function (): void {
    $run = function (string $query, int $resultCount, array $hitsByIndex): array {
        $history = [];
        $results = array_fill(0, $resultCount, ['hits' => [], 'estimatedTotalHits' => 0]);
        foreach ($hitsByIndex as $index => $hits) {
            $results[$index]['hits'] = $hits;
        }
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
        $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $provider->shouldReceive('client')->once()->andReturn($client);

        return [$provider->candidates($query), $history];
    };

    $camryStrict = [
        'id' => 20,
        'title' => 'Camry',
        'strict_transliteration_terms' => ['camry'],
        'vehicle_entities' => [[
            'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
            'model_id' => 20, 'model_title' => 'Camry', 'model_aliases' => [],
        ]],
    ];
    [$ruLayout, $ruHistory] = $run('сфькн', 8, [5 => [$camryStrict]]);
    expect($ruLayout['ids']['models'])->toBe([20])
        ->and($ruLayout['plan']['layout']['normalized'])->toBe('camry')
        ->and($ruLayout['channel_sources']['strict_transliteration'])->toBe('layout');
    $ruPayload = json_decode((string) $ruHistory[0]['request']->getBody(), true);
    expect($ruPayload['queries'])->toHaveCount(8)
        ->and($ruPayload['queries'][4]['q'])->toBe('camry')
        ->and($ruPayload['queries'][4]['attributesToSearchOn'])->toBe(['strict_transliteration_terms']);

    $camryPhonetic = [
        'id' => 20,
        'title' => 'Camry',
        'vehicle_entities' => [[
            'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
            'model_id' => 20, 'model_title' => 'Camry', 'model_aliases' => [],
        ]],
    ];
    $randomFuzzy = ['id' => 99, 'title' => 'Random Vehicle'];
    [$enLayout, $enHistory] = $run('rfvhb', 16, [1 => [$randomFuzzy], 9 => [$camryPhonetic]]);
    expect($enLayout['ids']['models'])->toBe([20])
        ->and($enLayout['plan']['layout']['normalized'])->toBe('камри')
        ->and($enLayout['channel_sources']['phonetic'])->toBe('layout');
    $enPayload = json_decode((string) $enHistory[0]['request']->getBody(), true);
    expect($enPayload['queries'])->toHaveCount(16)
        ->and($enPayload['queries'][8]['q'])->toBe('KMR')
        ->and($enPayload['queries'][8]['attributesToSearchOn'])->toBe(['phonetic_terms', 'structured_terms']);

    $toyotaStrict = [
        'id' => 1,
        'title' => 'Toyota',
        'strict_transliteration_terms' => ['toyota'],
        'vehicle_entities' => [[
            'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        ]],
    ];
    [$toyota, $toyotaHistory] = $run('njqjnf', 12, [4 => [$toyotaStrict]]);
    expect($toyota['ids']['makes'])->toBe([1])
        ->and($toyota['plan']['layout']['strict_transliteration'])->toBe('toyota');
    $toyotaPayload = json_decode((string) $toyotaHistory[0]['request']->getBody(), true);
    expect($toyotaPayload['queries'][4]['q'])->toBe('toyota');
});

test('phonetic candidate gate rejects Wish Vision Vista and unrelated Camry collisions before hydration', function (): void {
    $run = function (string $query, array $modelHits): array {
        $results = array_fill(0, 16, ['hits' => [], 'estimatedTotalHits' => 0]);
        $results[9]['hits'] = $modelHits;
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results])),
        ]));
        $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
        $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $provider->shouldReceive('client')->once()->andReturn($client);

        return $provider->candidates($query)['ids']['models'];
    };

    $wish = ['id' => 10, 'title' => 'Wish', 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 10, 'model_title' => 'Wish', 'model_aliases' => [],
    ]]];
    $vision = ['id' => 11, 'title' => 'Vision', 'vehicle_entities' => [[
        'make_id' => 2, 'make_title' => 'Chery', 'make_aliases' => [],
        'model_id' => 11, 'model_title' => 'Vision', 'model_aliases' => [],
    ]]];
    $vista = ['id' => 12, 'title' => 'Vista', 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 12, 'model_title' => 'Vista', 'model_aliases' => [],
    ]]];
    expect($run('виш', [$vision, $vista, $wish]))->toBe([10]);

    $camry = ['id' => 20, 'title' => 'Camry', 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 20, 'model_title' => 'Camry', 'model_aliases' => [],
    ]]];
    $chrysler = ['id' => 21, 'title' => 'Chrysler', 'vehicle_entities' => [[
        'make_id' => 3, 'make_title' => 'Chrysler', 'make_aliases' => [],
        'model_id' => 21, 'model_title' => 'Random', 'model_aliases' => [],
    ]]];
    expect($run('камри', [$chrysler, $camry]))->toBe([20]);
});

test('mixed phonetic queries keep short codes and numeric tokens exact per token', function (): void {
    $run = function (string $query, array $phoneticResults): array {
        $history = [];
        $results = array_fill(0, 16, ['hits' => [], 'estimatedTotalHits' => 0]);
        foreach ($phoneticResults as $index => $hits) {
            $results[$index]['hits'] = $hits;
        }
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
        $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $provider->shouldReceive('client')->once()->andReturn($client);

        return [
            $provider->candidates(
                $query,
                productQueryPlan: app(CatalogProductQueryPlanner::class)->emptyPlan($query),
            ),
            $history,
        ];
    };

    $bmwX5 = ['id' => 30, 'title' => 'X5', 'structured_terms' => ['x5'], 'vehicle_entities' => [[
        'make_id' => 7, 'make_title' => 'BMW', 'make_aliases' => [],
        'model_id' => 30, 'model_title' => 'X5', 'model_aliases' => [],
        'structured_terms' => ['x5'],
    ]]];
    $bmwX3 = ['id' => 31, 'title' => 'X3', 'structured_terms' => ['x3'], 'vehicle_entities' => [[
        'make_id' => 7, 'make_title' => 'BMW', 'make_aliases' => [],
        'model_id' => 31, 'model_title' => 'X3', 'model_aliases' => [],
        'structured_terms' => ['x3'],
    ]]];
    [$bmwResult, $bmwHistory] = $run('бмв X5', [9 => [$bmwX3, $bmwX5]]);
    expect($bmwResult['plan']['word_tokens'])->toBe(['bmv'])
        ->and($bmwResult['plan']['structured_tokens'])->toBe(['x5'])
        ->and($bmwResult['ids']['models'])->toBe([30]);
    $bmwPayload = json_decode((string) $bmwHistory[0]['request']->getBody(), true);
    expect($bmwPayload['queries'][8]['q'])->toBe('x5 BMF')
        ->and($bmwPayload['queries'][8]['attributesToSearchOn'])->toBe(['phonetic_terms', 'structured_terms'])
        ->and($bmwPayload['queries'][12]['q'])->toBe('x5 px3bmv')
        ->and($bmwPayload['queries'][12]['attributesToSearchOn'])->toBe(['phonetic_terms', 'phonetic_prefix_terms', 'structured_terms']);

    $camry2012Generation = ['id' => 41, 'title' => 'XV50', 'structured_terms' => ['2012'], 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 40, 'model_title' => 'Camry', 'model_aliases' => [],
        'generation_id' => 41, 'generation_title' => 'XV50',
        'structured_terms' => ['2012'],
    ]]];
    $camry2012Product = ['id' => 42, 'title' => 'Camry body part', 'structured_terms' => ['2012'], 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 40, 'model_title' => 'Camry', 'model_aliases' => [],
        'generation_id' => 41, 'generation_title' => 'XV50',
        'structured_terms' => ['2012'],
    ]]];
    [$yearResult, $yearHistory] = $run('камри 2012', [10 => [$camry2012Generation], 11 => [$camry2012Product]]);
    expect($yearResult['plan']['word_tokens'])->toBe(['kamri'])
        ->and($yearResult['plan']['structured_tokens'])->toBe(['2012'])
        ->and($yearResult['ids']['generations'])->toBe([41])
        ->and($yearResult['ids']['products'])->toBe([42]);
    $yearPayload = json_decode((string) $yearHistory[0]['request']->getBody(), true);
    expect($yearPayload['queries'][8]['q'])->toBe('2012 KMR')
        ->and($yearPayload['queries'][12]['q'])->toBe('2012 px5kamri');
});

test('russian phonetic prefix is confidence gated and short ambiguous prefixes do not open fuzzy channel', function (): void {
    $camry = ['id' => 20, 'title' => 'Camry', 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 20, 'model_title' => 'Camry', 'model_aliases' => [],
    ]]];
    $results = array_fill(0, 16, ['hits' => [], 'estimatedTotalHits' => 0]);
    $results[13]['hits'] = [$camry];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results])),
    ]));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->once()->andReturn($client);
    expect($provider->candidates('камр')['ids']['models'])->toBe([20]);

    $vision = ['id' => 21, 'title' => 'Vision', 'vehicle_entities' => [[
        'make_id' => 2, 'make_title' => 'Chery', 'make_aliases' => [],
        'model_id' => 21, 'model_title' => 'Vision', 'model_aliases' => [],
    ]]];
    $wish = ['id' => 22, 'title' => 'Wish', 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 22, 'model_title' => 'Wish', 'model_aliases' => [],
    ]]];
    $vista = ['id' => 23, 'title' => 'Vista', 'vehicle_entities' => [[
        'make_id' => 1, 'make_title' => 'Toyota', 'make_aliases' => [],
        'model_id' => 23, 'model_title' => 'Vista', 'model_aliases' => [],
    ]]];
    $ambiguousResults = array_fill(0, 16, ['hits' => [], 'estimatedTotalHits' => 0]);
    $ambiguousResults[13]['hits'] = [$vision, $wish, $vista];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $ambiguousResults])),
    ]));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->once()->andReturn($client);
    expect($provider->candidates('вис')['ids']['models'])->toBe([]);

    $history = [];
    $shortResults = array_fill(0, 8, ['hits' => [], 'estimatedTotalHits' => 0]);
    $shortResults[5]['hits'] = [
        $vision + ['strict_transliteration_terms' => ['vision']],
        $vista + ['strict_transliteration_terms' => ['vista']],
    ];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $shortResults])),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->once()->andReturn($client);
    $short = $provider->candidates('ви');
    expect($short['ids']['models'])->toBe([])
        ->and($short['plan']['channels'])->not->toContain('phonetic', 'phonetic_prefix')
        ->and($history)->toHaveCount(1);
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    expect($payload['queries'])->toHaveCount(8);
});

test('vehicle candidate refill is bounded and only runs when public hydration underfills a full window', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Refill Make']);
    $hidden = VehicleModel::factory()->count(50)->forMake($make)->create()->values();
    $public = collect();
    for ($i = 0; $i < 10; $i++) {
        $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Refill Public '.$i]);
        $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
        $product = Product::factory()->withDefaultVariant()->create();
        ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
        $public->push($model);
    }

    $initial = array_fill(0, 4, ['hits' => [], 'estimatedTotalHits' => 0]);
    $initial[1]['hits'] = $hidden->map(fn (VehicleModel $model): array => ['id' => $model->id, 'title' => $model->title])->all();
    $refill = [['hits' => $public->map(fn (VehicleModel $model): array => ['id' => $model->id, 'title' => $model->title])->all(), 'estimatedTotalHits' => 10]];
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $initial])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $refill])),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->twice()->andReturn($client);

    $result = $provider->search('Refill');
    expect($result['models'])->toHaveCount(10)
        ->and($history)->toHaveCount(2);
    $secondPayload = json_decode((string) $history[1]['request']->getBody(), true);
    expect($secondPayload['queries'])->toHaveCount(1)
        ->and($secondPayload['queries'][0]['offset'])->toBe(50)
        ->and($secondPayload['queries'][0]['limit'])->toBe(50);

    $initial = array_fill(0, 4, ['hits' => [], 'estimatedTotalHits' => 0]);
    $initial[1]['hits'] = $public
        ->map(fn (VehicleModel $model): array => ['id' => $model->id, 'title' => $model->title])
        ->concat($hidden->take(40)->map(fn (VehicleModel $model): array => ['id' => $model->id, 'title' => $model->title]))
        ->values()->all();
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $initial])),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->once()->andReturn($client);

    expect($provider->search('Refill')['models'])->toHaveCount(10)
        ->and($history)->toHaveCount(1);
});

test('vehicle refill batches make model and generation gaps into one bounded multi search round', function (): void {
    $publicMakes = collect();
    $publicModels = collect();
    $publicGenerations = collect();
    for ($i = 0; $i < 10; $i++) {
        $fixture = searchVehicleFixture('Refill Public Make '.$i, 'Refill Public Model '.$i);
        $publicMakes->push($fixture['make']);
        $publicModels->push($fixture['model']);
        $publicGenerations->push($fixture['generation']);
    }

    $hiddenMakes = VehicleMake::factory()->count(50)->create()->values();
    $hiddenModelMake = VehicleMake::factory()->create();
    $hiddenModels = VehicleModel::factory()->count(50)->forMake($hiddenModelMake)->create()->values();
    $hiddenGenerationMake = VehicleMake::factory()->create();
    $hiddenGenerationModel = VehicleModel::factory()->forMake($hiddenGenerationMake)->create();
    $hiddenGenerations = VehicleGeneration::factory()->count(50)->forVehicleModel($hiddenGenerationModel)->create()->values();

    $initial = array_fill(0, 4, ['hits' => [], 'estimatedTotalHits' => 0]);
    $initial[0]['hits'] = $hiddenMakes->map(fn (VehicleMake $make): array => ['id' => $make->id, 'title' => $make->title])->all();
    $initial[1]['hits'] = $hiddenModels->map(fn (VehicleModel $model): array => ['id' => $model->id, 'title' => $model->title])->all();
    $initial[2]['hits'] = $hiddenGenerations->map(fn (VehicleGeneration $generation): array => ['id' => $generation->id, 'title' => $generation->title])->all();
    $refill = [
        ['hits' => $publicMakes->map(fn (VehicleMake $make): array => ['id' => $make->id, 'title' => $make->title])->all(), 'estimatedTotalHits' => 10],
        ['hits' => $publicModels->map(fn (VehicleModel $model): array => ['id' => $model->id, 'title' => $model->title])->all(), 'estimatedTotalHits' => 10],
        ['hits' => $publicGenerations->map(fn (VehicleGeneration $generation): array => ['id' => $generation->id, 'title' => $generation->title])->all(), 'estimatedTotalHits' => 10],
    ];
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $initial])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $refill])),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->twice()->andReturn($client);

    $result = $provider->search('Refill');
    expect($result['makes'])->toHaveCount(10)
        ->and($result['models'])->toHaveCount(10)
        ->and($result['generations'])->toHaveCount(10)
        ->and($history)->toHaveCount(2);

    $payload = json_decode((string) $history[1]['request']->getBody(), true);
    expect($payload['queries'])->toHaveCount(3);
    foreach ($payload['queries'] as $query) {
        expect($query['offset'])->toBe(50)
            ->and($query['limit'])->toBe(50);
    }
});

test('vehicle candidate refill stops after three bounded windows even when sql visibility rejects everything', function (): void {
    $hits = static fn (int $start): array => array_map(
        static fn (int $id): array => ['id' => $id, 'title' => 'Missing Model '.$id],
        range($start, $start + 49),
    );

    $initial = array_fill(0, 4, ['hits' => [], 'estimatedTotalHits' => 0]);
    $initial[1]['hits'] = $hits(10000);
    $second = [['hits' => $hits(20000), 'estimatedTotalHits' => 50]];
    $third = [['hits' => [], 'estimatedTotalHits' => 0]];

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $initial])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $second])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $third])),
    ]));
    $stack->push(Middleware::history($history));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->times(3)->andReturn($client);

    expect($provider->search('Missing')['models'])->toHaveCount(0)
        ->and($history)->toHaveCount(3);

    $secondPayload = json_decode((string) $history[1]['request']->getBody(), true);
    $thirdPayload = json_decode((string) $history[2]['request']->getBody(), true);
    expect($secondPayload['queries'])->toHaveCount(1)
        ->and($secondPayload['queries'][0]['offset'])->toBe(50)
        ->and($secondPayload['queries'][0]['limit'])->toBe(50)
        ->and($thirdPayload['queries'])->toHaveCount(1)
        ->and($thirdPayload['queries'][0]['offset'])->toBe(100)
        ->and($thirdPayload['queries'][0]['limit'])->toBe(50);
});

test('search queue waits for the nginx meilisearch gateway', function (): void {
    $compose = file_get_contents(base_path('docker-compose.yml'));
    expect($compose)->not->toBeFalse();
    preg_match('/^  search-queue:\n(?<service>.*?)(?=^  [a-zA-Z0-9_-]+:\n)/ms', (string) $compose, $matches);

    expect($matches['service'] ?? '')
        ->toContain("    depends_on:\n")
        ->toContain("      nginx:\n        condition: service_started\n")
        ->toContain("      meilisearch:\n        condition: service_healthy\n")
        ->toContain("      redis:\n        condition: service_started\n");
});
