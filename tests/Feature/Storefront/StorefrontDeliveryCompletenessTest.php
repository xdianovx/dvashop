<?php

use App\Enums\LegalDocumentCode;
use App\Enums\StockStatus;
use App\Models\LegalDocument;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function assertStorefrontSeoMetadata(TestResponse $response, string $prefix): void
{
    $response->assertOk()
        ->assertSee('<title>'.$prefix.' Meta Title</title>', false)
        ->assertSee('<meta name="description" content="'.$prefix.' Meta Description">', false)
        ->assertSee($prefix.' SEO H1')
        ->assertSee($prefix.' SEO &lt;strong&gt;Text&lt;/strong&gt;', false)
        ->assertDontSee($prefix.' SEO <strong>Text</strong>', false)
        ->assertSee('<link rel="canonical" href="https://canonical.example/'.$prefix.'">', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertSee('<meta property="og:title" content="'.$prefix.' OG Title">', false)
        ->assertSee('<meta property="og:description" content="'.$prefix.' OG Description">', false)
        ->assertSee('<meta property="og:image" content="https://cdn.example/'.$prefix.'.jpg">', false);
}

function storefrontSeoFields(string $prefix): array
{
    return [
        'meta_title' => $prefix.' Meta Title',
        'meta_description' => $prefix.' Meta Description',
        'seo_h1' => $prefix.' SEO H1',
        'seo_text' => $prefix.' SEO <strong>Text</strong>',
        'canonical_url' => 'https://canonical.example/'.$prefix,
        'noindex' => true,
        'og_title' => $prefix.' OG Title',
        'og_description' => $prefix.' OG Description',
        'og_image' => 'https://cdn.example/'.$prefix.'.jpg',
    ];
}

test('public catalog entity pages use every SeoMetadataService field', function (): void {
    $category = ProductCategory::factory()->create(array_merge(
        ['title' => 'SEO Category', 'slug' => 'seo-category'],
        storefrontSeoFields('category'),
    ));
    $partType = PartType::factory()->forCategory($category)->create(array_merge(
        ['title' => 'SEO Part Type'],
        storefrontSeoFields('part-type'),
    ));
    $make = VehicleMake::factory()->create(array_merge(
        ['title' => 'SEO Make', 'slug' => 'seo-make'],
        storefrontSeoFields('make'),
    ));
    $model = VehicleModel::factory()->forMake($make)->create(array_merge(
        ['title' => 'SEO Model', 'slug' => 'seo-model'],
        storefrontSeoFields('model'),
    ));
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(array_merge(
        ['title' => 'SEO Generation', 'slug' => 'seo-generation'],
        storefrontSeoFields('generation'),
    ));
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create(array_merge(
        ['title' => 'SEO Product', 'slug' => 'seo-product'],
        storefrontSeoFields('product'),
    ));
    ProductVariant::factory()->forProduct($product)->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => null,
    ]);
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();

    assertStorefrontSeoMetadata($this->get(route('catalog.make', $make->slug)), 'make');
    assertStorefrontSeoMetadata($this->get(route('catalog.model', [$make->slug, $model->slug])), 'model');
    assertStorefrontSeoMetadata($this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug])), 'generation');
    assertStorefrontSeoMetadata($this->get(route('catalog.index', ['category' => $category->full_slug])), 'category');
    assertStorefrontSeoMetadata($this->get(route('catalog.index', ['part_type' => $partType->full_slug])), 'part-type');
    assertStorefrontSeoMetadata($this->get(route('products.show', $product->slug)), 'product');

    $make->forceFill(['meta_title' => 'Updated in admin'])->save();
    $this->get(route('catalog.make', $make->slug))
        ->assertOk()
        ->assertSee('<title>Updated in admin</title>', false)
        ->assertDontSee('<title>make Meta Title</title>', false);
});

test('part type filter uses its own SEO instead of generic search metadata', function (): void {
    $partType = PartType::factory()->create([
        'title' => 'Unique Part Type',
        'meta_title' => 'Unique Part Type SEO',
        'meta_description' => 'Unique Part Type Description',
        'seo_h1' => 'Unique Part Type H1',
    ]);

    $this->get(route('catalog.index', ['part_type' => $partType->full_slug]))
        ->assertOk()
        ->assertSee('<title>Unique Part Type SEO</title>', false)
        ->assertSee('Unique Part Type Description')
        ->assertSee('Unique Part Type H1')
        ->assertDontSee('Результаты поиска —', false);
});

test('robots and sitemap expose only active indexable public entities', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Indexed Make', 'slug' => 'indexed-make', 'noindex' => false]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Indexed Model', 'slug' => 'indexed-model', 'noindex' => false]);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'Indexed Generation', 'slug' => 'indexed-generation', 'noindex' => false]);

    $hiddenMake = VehicleMake::factory()->create(['title' => 'Hidden Make', 'slug' => 'hidden-make', 'noindex' => true]);
    $hiddenModel = VehicleModel::factory()->forMake($hiddenMake)->create(['title' => 'Hidden Model', 'slug' => 'hidden-model', 'noindex' => false]);
    $hiddenGeneration = VehicleGeneration::factory()->forVehicleModel($hiddenModel)->create(['title' => 'Hidden Generation', 'slug' => 'hidden-generation', 'noindex' => false]);
    VehicleModel::factory()->inactive()->forMake($make)->create(['title' => 'Inactive Model', 'slug' => 'inactive-model']);

    $publicProduct = Product::factory()->create(['title' => 'Indexed Product', 'slug' => 'indexed-product', 'noindex' => false]);
    ProductVariant::factory()->forProduct($publicProduct)->default()->create(['stock_quantity' => null]);
    ProductFitment::factory()->forProduct($publicProduct)->forVehicleGeneration($generation)->create();
    $hiddenProduct = Product::factory()->create(['title' => 'Hidden Product', 'slug' => 'hidden-product', 'noindex' => true]);
    ProductVariant::factory()->forProduct($hiddenProduct)->default()->create(['stock_quantity' => null]);
    ProductFitment::factory()->forProduct($hiddenProduct)->forVehicleGeneration($hiddenGeneration)->create();
    $unavailableProduct = Product::factory()->create(['title' => 'Unavailable Product', 'slug' => 'unavailable-product', 'noindex' => false]);
    ProductVariant::factory()->forProduct($unavailableProduct)->inactive()->create(['stock_quantity' => null]);

    LegalDocument::query()->create([
        'code' => LegalDocumentCode::PrivacyPolicy,
        'title' => 'Privacy',
        'body' => 'Active legal document',
        'is_active' => true,
    ]);
    LegalDocument::query()->create([
        'code' => LegalDocumentCode::SaleRules,
        'title' => 'Inactive legal',
        'body' => 'Not public',
        'is_active' => false,
    ]);

    $this->get(route('robots'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Disallow: /admin')
        ->assertSee('Disallow: /cart')
        ->assertSee('Disallow: /checkout')
        ->assertSee('Sitemap: '.route('sitemap'));

    $sitemap = $this->get(route('sitemap'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    foreach ([
        route('home'),
        route('about'),
        route('faq'),
        route('catalog.make', $make->slug),
        route('catalog.model', [$make->slug, $model->slug]),
        route('catalog.generation', [$make->slug, $model->slug, $generation->slug]),
        route('products.show', $publicProduct->slug),
        route('legal.privacy-policy'),
    ] as $url) {
        $sitemap->assertSee(e($url), false);
    }

    foreach (['hidden-make', 'hidden-model', 'hidden-generation', 'hidden-product', 'unavailable-product', route('legal.sale-rules')] as $value) {
        $sitemap->assertDontSee($value, false);
    }

    expect(File::exists(public_path('robots.txt')))->toBeFalse();
});

test('storefront pagination keeps query strings and has desktop and mobile contracts', function (): void {
    $category = ProductCategory::factory()->create(['title' => 'Pagination Category', 'slug' => 'pagination-category']);

    Product::factory()
        ->count(13)
        ->forCategory($category)
        ->withDefaultVariant()
        ->create();

    $response = $this->get(route('catalog.index', [
        'category' => $category->full_slug,
        'marker' => 'kept',
    ]))->assertOk();

    $response->assertSee('class="storefront-pagination"', false)
        ->assertSee('storefront-pagination__pages', false)
        ->assertSee('storefront-pagination__mobile', false)
        ->assertSee('marker=kept', false)
        ->assertSee('page=2', false)
        ->assertDontSee('aria-label="Pagination Navigation"', false);

    $styles = File::get(resource_path('scss/_storefront-pagination.scss'));
    expect($styles)
        ->toContain('.storefront-pagination')
        ->toContain('@media (max-width: 640px)')
        ->toContain('.storefront-pagination__mobile');
});

test('vehicle search JavaScript remains progressive and aborts stale model requests', function (): void {
    $script = File::get(resource_path('js/app.js'));

    expect($script)
        ->toContain("document.querySelectorAll('[data-vehicle-search]')")
        ->toContain('new AbortController()')
        ->toContain('request?.abort()')
        ->toContain("headers: { Accept: 'application/json' }")
        ->toContain('model.disabled = true')
        ->toContain('model.disabled = false');
});

test('runtime storefront files contain no legacy 2POROGA branding tokens', function (): void {
    $paths = [app_path(), resource_path('views')];

    foreach ($paths as $path) {
        foreach (File::allFiles($path) as $file) {
            expect($file->getContents(), $file->getPathname())->not->toContain('2POROGA');
        }
    }

    $this->seed(ShopSettingsSeeder::class);
    ShopSetting::query()->firstOrFail()->forceFill(['store_name' => 'МагазПороги Тест'])->save();

    $this->get(route('cart.show'))
        ->assertOk()
        ->assertSee('Моя корзина — МагазПороги Тест', false);
    $this->get('/missing-storefront-page')
        ->assertNotFound()
        ->assertSee('Страница не найдена — МагазПороги Тест', false);
});
