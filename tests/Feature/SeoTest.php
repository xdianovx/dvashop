<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seoCatalogProduct(array $productState = []): array
{
    $make = VehicleMake::factory()->create([
        'title' => 'Toyota',
        'slug' => 'toyota',
        'norm_key' => 'toyota',
        'is_active' => true,
    ]);

    $model = VehicleModel::factory()->forMake($make)->create([
        'title' => 'Camry',
        'slug' => 'camry',
        'norm_key' => 'camry',
        'is_active' => true,
    ]);

    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'XV70',
        'slug' => 'xv70',
        'norm_key' => 'xv70',
        'years_label' => '2017-2023',
        'body' => 'седан',
        'is_active' => true,
    ]);

    $category = ProductCategory::factory()->create([
        'title' => 'Пороги',
        'slug' => 'porogi',
        'is_active' => true,
        'meta_title' => 'Пороги для автомобилей — 2POROGA',
        'meta_description' => 'Каталог автомобильных порогов.',
    ]);

    $product = Product::factory()->forCategory($category)->create(array_merge([
        'title' => 'Порог для Toyota Camry XV70',
        'slug' => 'porog-toyota-camry-xv70',
        'sku' => 'PROD-TOYOTA-CAMRY-XV70',
        'status' => ProductStatus::Active,
        'short_description' => 'Кузовной порог для Toyota Camry XV70.',
        'meta_title' => 'Порог Toyota Camry XV70 купить',
        'meta_description' => 'Порог Toyota Camry XV70 с доставкой.',
    ], $productState));

    $variant = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'VAR-TOYOTA-CAMRY-XV70',
        'price' => 2500,
        'is_active' => true,
    ]);

    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->primary()->create();

    return [$make, $model, $generation, $category, $product, $variant];
}

test('public pages render meta description and canonical', function () {
    [$make, $model, $generation, $category, $product] = seoCatalogProduct();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('<link rel="canonical" href="'.route('home').'">', false);

    $this->get(route('catalog.index'))
        ->assertOk()
        ->assertSee('Каталог автотоваров по маркам — 2POROGA')
        ->assertSee('<link rel="canonical" href="'.route('catalog.index').'">', false);

    $this->get(route('catalog.make', $make->slug))
        ->assertOk()
        ->assertSee('Каталог кузовных деталей и автотоваров для автомобилей Toyota.', false)
        ->assertSee('<link rel="canonical" href="'.route('catalog.make', $make->slug).'">', false);

    $this->get(route('catalog.model', [$make->slug, $model->slug]))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('catalog.model', [$make->slug, $model->slug]).'">', false);

    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('catalog.generation', [$make->slug, $model->slug, $generation->slug]).'">', false);

    $this->get(route('catalog.category', $category->full_slug))
        ->assertOk()
        ->assertSee('Пороги для автомобилей — 2POROGA')
        ->assertSee('Каталог автомобильных порогов.')
        ->assertSee('<link rel="canonical" href="'.route('catalog.category', $category->full_slug).'">', false);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Порог Toyota Camry XV70 купить')
        ->assertSee('Порог Toyota Camry XV70 с доставкой.')
        ->assertSee('<link rel="canonical" href="'.route('products.show', $product->slug).'">', false);
});

test('robots txt points to sitemap', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('User-agent: *')
        ->assertSee('Sitemap: '.route('sitemap'));
});

test('sitemap contains only active public catalog urls', function () {
    [$make, $model, $generation, , $product] = seoCatalogProduct();

    $draft = Product::factory()->draft()->create([
        'title' => 'Draft Product',
        'slug' => 'draft-product',
    ]);
    ProductVariant::factory()->forProduct($draft)->default()->create(['is_active' => true]);

    $archived = Product::factory()->archived()->create([
        'title' => 'Archived Product',
        'slug' => 'archived-product',
    ]);
    ProductVariant::factory()->forProduct($archived)->default()->create(['is_active' => true]);

    VehicleMake::factory()->inactive()->create([
        'title' => 'Hidden Make',
        'slug' => 'hidden-make',
        'norm_key' => 'hidden-make',
    ]);

    $response = $this->get('/sitemap.xml')->assertOk();
    $content = $response->getContent();

    expect($content)->toContain('<urlset')
        ->and($content)->toContain(route('home'))
        ->and($content)->toContain(route('catalog.index'))
        ->and($content)->toContain(route('catalog.make', $make->slug))
        ->and($content)->toContain(route('catalog.model', [$make->slug, $model->slug]))
        ->and($content)->toContain(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))
        ->and($content)->toContain(route('products.show', $product->slug))
        ->and($content)->not->toContain('draft-product')
        ->and($content)->not->toContain('archived-product')
        ->and($content)->not->toContain('hidden-make');
});
