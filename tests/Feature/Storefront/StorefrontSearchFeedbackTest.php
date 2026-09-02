<?php

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function prompt5ISearchGroup(string $html, string $group): string
{
    preg_match(
        '/<section[^>]+data-search-group="'.preg_quote($group, '/').'"[^>]*>(.*?)<\/section>/s',
        $html,
        $matches,
    );

    return $matches[1] ?? '';
}

test('catalog query renders makes models generations and products as separate approved card groups', function (): void {
    $make = VehicleMake::factory()->create([
        'title' => 'Acura',
        'slug' => 'acura',
    ]);
    $model = VehicleModel::factory()->forMake($make)->create([
        'title' => 'Integra',
        'slug' => 'integra',
    ]);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'III',
        'slug' => 'iii-sedan',
        'body' => 'Седан',
        'years_label' => '1993–2001',
    ]);
    $product = Product::factory()->create(['title' => 'Порог для Acura']);
    ProductVariant::factory()->forProduct($product)->default()->create([
        'price' => 3500,
        'stock_quantity' => null,
    ]);
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();

    $response = $this->get(route('catalog.index', ['q' => 'Acura']))->assertOk();
    $html = $response->getContent();
    $makes = prompt5ISearchGroup($html, 'makes');
    $models = prompt5ISearchGroup($html, 'models');
    $generations = prompt5ISearchGroup($html, 'generations');
    $products = prompt5ISearchGroup($html, 'products');

    $response->assertSeeInOrder(['Марки', 'Модели', 'Поколения', 'Детали и товары']);
    expect($makes)
        ->toContain('brand-card')
        ->toContain('Acura')
        ->not->toContain('Integra')
        ->and($models)
        ->toContain('model-card--model')
        ->toContain('Integra')
        ->toContain('Acura')
        ->not->toContain('brand-card')
        ->and($generations)
        ->toContain('model-card--body')
        ->toContain('III')
        ->toContain('Седан')
        ->and($products)
        ->toContain('product-card')
        ->toContain('Порог для Acura');
});

test('catalog search finds a public product by product sku', function (): void {
    $product = Product::factory()->create([
        'title' => 'Product SKU search fixture',
        'sku' => 'PROD-ABC-123',
    ]);
    ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => null,
        'price' => 2500,
        'stock_quantity' => null,
    ]);

    $this->get(route('catalog.index', ['q' => 'PROD-ABC-123']))
        ->assertOk()
        ->assertSee('Product SKU search fixture');

    $this->get(route('catalog.index', ['q' => 'ABC-12']))
        ->assertOk()
        ->assertSee('Product SKU search fixture');
});

test('catalog search finds only public variant sku matches', function (): void {
    $product = Product::factory()->create([
        'title' => 'Variant SKU search fixture',
        'sku' => null,
    ]);
    ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'VAR-XYZ-987',
        'price' => 2500,
        'stock_quantity' => null,
    ]);
    ProductVariant::factory()->forProduct($product)->inactive()->create([
        'sku' => 'VAR-HIDDEN-654',
        'price' => 2500,
        'stock_quantity' => null,
    ]);

    $this->get(route('catalog.index', ['q' => 'VAR-XYZ-987']))
        ->assertOk()
        ->assertSee('Variant SKU search fixture');

    $this->get(route('catalog.index', ['q' => 'XYZ-98']))
        ->assertOk()
        ->assertSee('Variant SKU search fixture');

    $this->get(route('catalog.index', ['q' => 'VAR-HIDDEN-654']))
        ->assertOk()
        ->assertDontSee('Variant SKU search fixture');
});

test('catalog search remains safe when product and public variant skus are empty', function (): void {
    $product = Product::factory()->create([
        'title' => 'Empty SKU fixture',
        'sku' => null,
    ]);
    ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => null,
        'price' => 2500,
        'stock_quantity' => null,
    ]);

    $this->get(route('catalog.index', ['q' => 'SKU-NOT-PRESENT']))
        ->assertOk()
        ->assertDontSee('Empty SKU fixture');
});

test('catalog search preserves infix title visibility and pagination query state', function (): void {
    foreach (range(1, 13) as $index) {
        $product = Product::factory()->create([
            'title' => "Prefix MiddleNeedle Suffix {$index}",
            'sku' => null,
            'position' => $index,
        ]);
        ProductVariant::factory()->forProduct($product)->default()->create([
            'sku' => null,
            'price' => 2500,
            'stock_quantity' => null,
        ]);
    }

    $archived = Product::factory()->archived()->create(['title' => 'Archived MiddleNeedle product']);
    ProductVariant::factory()->forProduct($archived)->default()->create([
        'sku' => null,
        'price' => 2500,
        'stock_quantity' => null,
    ]);

    $response = $this->get(route('catalog.index', ['q' => 'MiddleNeedle']))->assertOk();
    $products = $response->viewData('products');

    expect($products->total())->toBe(13)
        ->and($products->nextPageUrl())->toContain('q=MiddleNeedle');
    $response->assertDontSee('Archived MiddleNeedle product');
});

test('vehicle search groups are bounded and the global empty state has no empty headings', function (): void {
    $make = VehicleMake::factory()->create([
        'title' => 'Bounded Make',
        'slug' => 'bounded-make',
    ]);

    foreach (range(1, 12) as $index) {
        $model = VehicleModel::factory()->forMake($make)->create([
            'title' => "Bounded Model {$index}",
            'slug' => "bounded-model-{$index}",
        ]);
        $generation = VehicleGeneration::factory()->forVehicleModel($model)->create([
            'title' => "Public Generation {$index}",
            'slug' => "public-generation-{$index}",
        ]);
        $product = Product::factory()->withDefaultVariant()->create(['title' => "Bounded public product {$index}"]);
        ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
    }

    $bounded = $this->get(route('catalog.index', ['q' => 'Bounded']))->assertOk();
    expect($bounded->viewData('vehicleMakes'))->toHaveCount(1)
        ->and($bounded->viewData('vehicleModels'))->toHaveCount(10)
        ->and($bounded->viewData('vehicleGenerations'))->toHaveCount(10);

    $empty = $this->get(route('catalog.index', ['q' => 'definitely-not-found']))
        ->assertOk()
        ->assertSee('По вашему запросу ничего не найдено.')
        ->assertDontSee('data-search-group="makes"', false)
        ->assertDontSee('data-search-group="models"', false)
        ->assertDontSee('data-search-group="generations"', false)
        ->assertDontSee('data-search-group="products"', false)
        ->assertDontSee('id="catalog-makes-title"', false)
        ->assertDontSee('id="catalog-models-title"', false)
        ->assertDontSee('id="catalog-generations-title"', false)
        ->assertDontSee('id="catalog-products-title"', false);
});

test('global request feedback preserves ajax fallbacks and accessible loading contracts', function (): void {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $loader = file_get_contents(resource_path('views/components/storefront-loader.blade.php'));
    $search = file_get_contents(resource_path('views/components/search.blade.php'));
    $inquiry = file_get_contents(resource_path('views/components/storefront-inquiry-modal.blade.php'));
    $script = file_get_contents(resource_path('js/app.js'));
    $styles = file_get_contents(resource_path('scss/_storefront-loader.scss'));

    expect($layout)
        ->toContain('<x-storefront-loader />')
        ->and($loader)
        ->toContain('data-storefront-loader')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('aria-atomic="true"')
        ->and($search)
        ->toContain('data-vehicle-search-status')
        ->toContain('data-vehicle-search-spinner')
        ->toContain('aria-live="polite"')
        ->and($inquiry)
        ->toContain('data-inquiry-submit-label')
        ->toContain('method="POST"')
        ->and($script)
        ->toContain('function beginRequest(')
        ->toContain('function endRequest()')
        ->toContain('activeRequestCount')
        ->toContain("beginRequest('Загружаем модели…')")
        ->toContain("beginRequest('Отправляем заявку…')")
        ->toContain('finally {')
        ->toContain('endRequest();')
        ->toContain("error.name === 'AbortError'")
        ->toContain("link.matches('[data-inquiry-open], [download]')")
        ->toContain("href.startsWith('#')")
        ->toContain("href.toLowerCase().startsWith('javascript:')")
        ->toContain('url.origin !== window.location.origin')
        ->toContain("window.addEventListener('pageshow', resetRequestUi)")
        ->not->toContain("event.preventDefault();\n\n    link.dataset.requestPending")
        ->and($styles)
        ->toContain('pointer-events: none')
        ->toContain('@media (max-width: 768px)')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});
