<?php

use App\Models\Cart;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(RefreshDatabase::class);

/** @return array{count:int, queries:array<int, array{query:string,bindings:array,time:float}>} */
function storefrontCommerceQueries(TestCase $test, string $url): array
{
    DB::disableQueryLog();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->get($url)->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return ['count' => count($queries), 'queries' => $queries];
}

function storefrontCommerceCartRequest(Cart $cart): Request
{
    return Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
}

test('catalog product cards without images do not add a part type query per product', function (): void {
    $category = ProductCategory::factory()->create(['title' => 'Query category']);
    $firstPartType = PartType::factory()->forCategory($category)->create(['title' => 'Query type 01']);
    Product::factory()->forCategory($category)->forPartType($firstPartType)->withDefaultVariant()->create([
        'title' => 'Query card 01',
        'position' => 1,
    ]);
    $url = route('catalog.index', ['q' => 'Query card']);
    $one = storefrontCommerceQueries($this, $url);

    foreach (range(2, 20) as $index) {
        $partType = PartType::factory()->forCategory($category)->create(['title' => sprintf('Query type %02d', $index)]);
        Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create([
            'title' => sprintf('Query card %02d', $index),
            'position' => $index,
        ]);
    }
    $twenty = storefrontCommerceQueries($this, $url);

    $onePartTypeQueries = collect($one['queries'])->filter(fn (array $query): bool => str_contains($query['query'], 'from "part_types"'))->count();
    $twentyPartTypeQueries = collect($twenty['queries'])->filter(fn (array $query): bool => str_contains($query['query'], 'from "part_types"'))->count();

    expect($twenty['count'])->toBeLessThanOrEqual($one['count'] + 2)
        ->and($twentyPartTypeQueries)->toBeLessThanOrEqual($onePartTypeQueries);
});

test('related product cards without images do not add a part type query per product', function (): void {
    $category = ProductCategory::factory()->create(['title' => 'Related query category']);
    $mainPartType = PartType::factory()->forCategory($category)->create(['title' => 'Main related type']);
    $main = Product::factory()->forCategory($category)->forPartType($mainPartType)->withDefaultVariant()->create(['title' => 'Main query product']);
    $firstRelatedType = PartType::factory()->forCategory($category)->create(['title' => 'Related type 01']);
    Product::factory()->forCategory($category)->forPartType($firstRelatedType)->withDefaultVariant()->create(['title' => 'Related query 01', 'position' => 1]);
    $url = route('products.show', $main->slug);
    $one = storefrontCommerceQueries($this, $url);

    foreach (range(2, 20) as $index) {
        $partType = PartType::factory()->forCategory($category)->create(['title' => sprintf('Related type %02d', $index)]);
        Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create([
            'title' => sprintf('Related query %02d', $index),
            'position' => $index,
        ]);
    }
    $twenty = storefrontCommerceQueries($this, $url);

    $onePartTypeQueries = collect($one['queries'])->filter(fn (array $query): bool => str_contains($query['query'], 'from "part_types"'))->count();
    $twentyPartTypeQueries = collect($twenty['queries'])->filter(fn (array $query): bool => str_contains($query['query'], 'from "part_types"'))->count();

    expect($twenty['count'])->toBeLessThanOrEqual($one['count'] + 2)
        ->and($twentyPartTypeQueries)->toBeLessThanOrEqual($onePartTypeQueries);
});

test('category descendant filtering uses one bounded category query for a deep wide tree', function (): void {
    $root = ProductCategory::factory()->create(['title' => 'Bounded root', 'slug' => 'bounded-root']);
    Product::factory()->forCategory($root)->withDefaultVariant()->create(['title' => 'Bounded root product']);
    $url = route('catalog.index', ['category' => $root->full_slug]);
    $one = storefrontCommerceQueries($this, $url);

    foreach (range(1, 20) as $index) {
        $child = ProductCategory::factory()->create([
            'parent_id' => $root->getKey(),
            'title' => sprintf('Bounded child %02d', $index),
            'slug' => sprintf('bounded-child-%02d', $index),
        ]);
        ProductCategory::factory()->create([
            'parent_id' => $child->getKey(),
            'title' => sprintf('Bounded grandchild %02d', $index),
            'slug' => sprintf('bounded-grandchild-%02d', $index),
        ]);
    }

    $many = storefrontCommerceQueries($this, $url);
    $categoryQueries = collect($many['queries'])->filter(fn (array $query): bool => str_contains($query['query'], 'from "product_categories"'));

    expect($many['count'])->toBeLessThanOrEqual($one['count'] + 2)
        ->and($categoryQueries->filter(fn (array $query): bool => str_contains($query['query'], 'full_slug') && str_contains($query['query'], 'like'))->count())->toBe(1);
});

test('product page query count does not grow with the number of public variants', function (): void {
    $product = Product::factory()->create(['title' => 'Variant query product']);
    ProductVariant::factory()->forProduct($product)->default()->create();
    $url = route('products.show', $product->slug);
    $one = storefrontCommerceQueries($this, $url);

    ProductVariant::factory()->forProduct($product)->count(19)->create();
    $twenty = storefrontCommerceQueries($this, $url);

    expect($twenty['count'])->toBeLessThanOrEqual($one['count'] + 2);
});

test('product page query count does not grow with the number of visible gallery images', function (): void {
    $product = Product::factory()->withDefaultVariant()->create(['title' => 'Gallery query product']);
    ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'https://cdn.example.test/query-main.jpg',
        'position' => 1,
    ]);
    $url = route('products.show', $product->slug);
    $one = storefrontCommerceQueries($this, $url);

    ProductImage::factory()->forProduct($product)->count(19)->sequence(
        fn ($sequence): array => [
            'path' => sprintf('https://cdn.example.test/query-%02d.jpg', $sequence->index + 2),
            'position' => $sequence->index + 2,
        ],
    )->create();
    $twenty = storefrontCommerceQueries($this, $url);

    expect($twenty['count'])->toBeLessThanOrEqual($one['count'] + 2);
});

test('cart page query count does not grow with the number of cart items', function (): void {
    $cart = Cart::factory()->create();
    $manager = app(CartManager::class);
    $first = ProductVariant::factory()->default()->create(['stock_quantity' => null]);
    $manager->addItem(storefrontCommerceCartRequest($cart), $first->getKey(), 1);
    $url = route('cart.show');

    $one = $this->withCookie(CartManager::COOKIE_NAME, $cart->token);
    DB::disableQueryLog();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $one->get($url)->assertOk();
    $oneCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(2, 20) as $index) {
        $variant = ProductVariant::factory()->default()->create(['stock_quantity' => null]);
        $manager->addItem(storefrontCommerceCartRequest($cart), $variant->getKey(), 1);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get($url)->assertOk();
    $twentyCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($twentyCount)->toBeLessThanOrEqual($oneCount + 2);
});

test('model page query count does not grow with the number of generations', function (): void {
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    VehicleGeneration::factory()->forVehicleModel($model)->create();
    $url = route('catalog.model', [$make->slug, $model->slug]);
    $one = storefrontCommerceQueries($this, $url);

    VehicleGeneration::factory()->forVehicleModel($model)->count(19)->create();
    $twenty = storefrontCommerceQueries($this, $url);

    expect($twenty['count'])->toBeLessThanOrEqual($one['count'] + 2);
});
