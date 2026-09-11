<?php

use App\Models\Product;
use App\Services\Search\DatabaseCatalogSearchProvider;
use App\Services\Search\MeilisearchCatalogSearchProvider;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client;

uses(RefreshDatabase::class);

function searchWindowResponse(array $ids, bool $initial): Response
{
    $results = [['hits' => array_map(fn ($id) => ['id' => $id], $ids), 'estimatedTotalHits' => 999999]];
    if ($initial) {
        $results = array_merge(array_fill(0, 3, ['hits' => []]), $results);
    }

    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['results' => $results]));
}

function paginatedSearchProvider(array $responses, array &$history): MeilisearchCatalogSearchProvider
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new Client('http://search.invalid', null, new HttpClient(['handler' => $stack]));
    $provider = Mockery::mock(MeilisearchCatalogSearchProvider::class, [app(DatabaseCatalogSearchProvider::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $provider->shouldReceive('client')->andReturn($client);

    return $provider;
}

test('stale candidates refill a complete page with a real lookahead and no fabricated total', function (): void {
    $products = Product::factory()->count(24)->withDefaultVariant()->create();
    $ids = $products->pluck('id')->all();
    $hidden = range(10000, 10039);
    $history = [];
    $provider = paginatedSearchProvider([
        searchWindowResponse(array_merge($hidden, array_slice($ids, 0, 10)), true),
        // Overlapping candidates must be deduplicated, too.
        searchWindowResponse(array_merge([$ids[9]], array_slice($ids, 10)), false),
    ], $history);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $provider->search('fixture')['products'];
    $sql = DB::getQueryLog();
    DB::disableQueryLog();
    expect($result)->toHaveCount(12)
        ->and($result->getCollection()->pluck('id')->all())->toBe(array_slice($ids, 0, 12))
        ->and(method_exists($result, 'total'))->toBeFalse()
        ->and($result->nextPageUrl())->toContain('search_cursor=')
        ->and($result->previousPageUrl())->toBeNull()
        ->and($history)->toHaveCount(2)
        ->and(count($sql))->toBeLessThanOrEqual(18);
    $request = json_decode((string) $history[1]['request']->getBody(), true);
    expect($request['queries'])->toHaveCount(1)
        ->and($request['queries'][0]['offset'])->toBe(50);
});

test('cursor page transitions do not repeat products or skip the unused part of a window', function (): void {
    $products = Product::factory()->count(24)->withDefaultVariant()->create();
    $ids = $products->pluck('id')->all();
    $history = [];
    $provider = paginatedSearchProvider([
        searchWindowResponse(array_merge(range(10000, 10039), array_slice($ids, 0, 10)), true),
        searchWindowResponse(array_slice($ids, 10), false),
    ], $history);
    $first = $provider->search('fixture')['products'];
    $cursor = $first->nextCursor();
    expect($cursor->parameter('offset'))->toBe(52);
    request()->query->set('search_cursor', $cursor->encode());
    $history = [];
    $second = paginatedSearchProvider([searchWindowResponse(array_slice($ids, 12), true)], $history)->search('fixture')['products'];
    expect($second->getCollection()->pluck('id')->all())->toBe(array_slice($ids, 12))
        ->and($second->nextPageUrl())->toBeNull()
        ->and($second->previousPageUrl())->not->toBeNull()
        ->and(array_intersect($first->getCollection()->pluck('id')->all(), $second->getCollection()->pluck('id')->all()))->toBe([]);
    request()->query->set('search_cursor', $second->previousCursor()->encode());
    $history = [];
    $previous = paginatedSearchProvider([
        searchWindowResponse(array_merge(range(10002, 10039), array_slice($ids, 0, 12)), true),
        searchWindowResponse([10000, 10001], false),
    ], $history)->search('fixture')['products'];
    expect($previous->getCollection()->pluck('id')->all())->toBe(array_slice($ids, 0, 12))
        ->and($previous->previousPageUrl())->toBeNull();
});

test('hard cap bounds requests and SQL while offering an honest continuation for stale indexes', function (): void {
    $history = [];
    $provider = paginatedSearchProvider([
        searchWindowResponse(range(10000, 10049), true),
        searchWindowResponse(range(10050, 10099), false),
        searchWindowResponse(range(10100, 10149), false),
    ], $history);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $provider->search('fixture')['products'];
    $sql = DB::getQueryLog();
    DB::disableQueryLog();
    expect($result)->toBeEmpty()
        ->and($result->scanLimited)->toBeTrue()
        ->and($result->nextCursor()->parameter('offset'))->toBe(150)
        ->and($history)->toHaveCount(3)
        ->and(count($sql))->toBeLessThanOrEqual(8);
    $html = view('components.storefront-pagination', ['paginator' => $result])->render();
    expect($html)->toContain('Искать дальше')->not->toContain('999999', 'Страница');
});
