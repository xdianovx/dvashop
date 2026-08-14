<?php

use App\Enums\ProductStatus;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\PublicCatalogCache;
use App\Services\PublicVehicleCatalogVisibility;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{make:VehicleMake,model:VehicleModel,generation:VehicleGeneration,product:Product,variant:ProductVariant} */
function p5pPublicVehicleFixture(string $token, array $productAttributes = [], array $variantAttributes = []): array
{
    $slug = str($token)->slug()->toString();
    $make = VehicleMake::factory()->create(['title' => $token.' Make', 'slug' => $slug.'-make']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => $token.' Model', 'slug' => $slug.'-model']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => $token.' Generation',
        'slug' => $slug.'-generation',
    ]);
    $product = Product::factory()->create(array_merge(['title' => $token.' Product'], $productAttributes));
    $variant = ProductVariant::factory()->forProduct($product)->default()->create(array_merge([
        'price' => 2500,
        'stock_quantity' => null,
        'is_active' => true,
    ], $variantAttributes));
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();

    return compact('make', 'model', 'generation', 'product', 'variant');
}

test('public vehicle visibility follows existing product and variant availability without using sellable price', function (): void {
    $public = p5pPublicVehicleFixture('Public');
    $zeroPrice = p5pPublicVehicleFixture('Zero Price', ['price' => 0], ['price' => 0]);
    $archived = p5pPublicVehicleFixture('Archived');
    $archived['product']->update(['status' => ProductStatus::Archived]);
    $softDeleted = p5pPublicVehicleFixture('Soft Deleted');
    $softDeleted['product']->delete();
    $inactiveVariant = p5pPublicVehicleFixture('Inactive Variant');
    DB::table('product_variants')->where('id', $inactiveVariant['variant']->getKey())->update(['is_active' => false]);
    $inactiveCategory = p5pPublicVehicleFixture('Inactive Category');
    DB::table('product_categories')->where('id', $inactiveCategory['product']->product_category_id)->update(['is_active' => false]);

    $partType = PartType::factory()->create(['title' => 'Visibility Part Type']);
    $inactivePartType = p5pPublicVehicleFixture('Inactive Part Type', ['part_type_id' => $partType->getKey()]);
    DB::table('part_types')->where('id', $partType->getKey())->update(['is_active' => false]);

    $mixed = p5pPublicVehicleFixture('Mixed');
    $mixedArchivedProduct = Product::factory()->archived()->withDefaultVariant()->create(['title' => 'Mixed archived sibling']);
    ProductFitment::factory()->forProduct($mixedArchivedProduct)->forVehicleGeneration($mixed['generation'])->create();

    $visibility = app(PublicVehicleCatalogVisibility::class);
    $generationIds = $visibility->generations(VehicleGeneration::query())->pluck('id')->all();
    $modelIds = $visibility->models(VehicleModel::query())->pluck('id')->all();
    $makeIds = $visibility->makes(VehicleMake::query())->pluck('id')->all();

    expect($generationIds)->toContain($public['generation']->getKey(), $zeroPrice['generation']->getKey(), $mixed['generation']->getKey())
        ->not->toContain(
            $archived['generation']->getKey(),
            $softDeleted['generation']->getKey(),
            $inactiveVariant['generation']->getKey(),
            $inactiveCategory['generation']->getKey(),
            $inactivePartType['generation']->getKey(),
        )
        ->and($modelIds)->toContain($public['model']->getKey(), $zeroPrice['model']->getKey(), $mixed['model']->getKey())
        ->not->toContain($archived['model']->getKey(), $inactiveVariant['model']->getKey())
        ->and($makeIds)->toContain($public['make']->getKey(), $zeroPrice['make']->getKey(), $mixed['make']->getKey())
        ->not->toContain($archived['make']->getKey(), $inactiveVariant['make']->getKey());
});

test('catalog vehicle routes hide empty active nodes and return after the same Product is reactivated', function (): void {
    $public = p5pPublicVehicleFixture('Lifecycle Route');
    $emptyModel = VehicleModel::factory()->forMake($public['make'])->create([
        'title' => 'Empty sibling model',
        'slug' => 'empty-sibling-model',
    ]);

    $this->get(route('catalog.make', $public['make']->slug))
        ->assertOk()
        ->assertSee($public['model']->title)
        ->assertDontSee($emptyModel->title);
    $this->get(route('catalog.model', [$public['make']->slug, $public['model']->slug]))->assertOk();
    $this->get(route('catalog.generation', [$public['make']->slug, $public['model']->slug, $public['generation']->slug]))->assertOk();
    $this->get(route('catalog.model', [$public['make']->slug, $emptyModel->slug]))->assertNotFound();

    $public['product']->update(['status' => ProductStatus::Archived]);
    $this->get(route('catalog.make', $public['make']->slug))->assertNotFound();
    $this->get(route('catalog.model', [$public['make']->slug, $public['model']->slug]))->assertNotFound();
    $this->get(route('catalog.generation', [$public['make']->slug, $public['model']->slug, $public['generation']->slug]))->assertNotFound();

    $public['product']->update(['status' => ProductStatus::Active]);
    $this->get(route('catalog.make', $public['make']->slug))->assertOk();
    $this->get(route('catalog.model', [$public['make']->slug, $public['model']->slug]))->assertOk();
    $this->get(route('catalog.generation', [$public['make']->slug, $public['model']->slug, $public['generation']->slug]))->assertOk();
});

test('catalog index homepage and dependent model ajax expose only public vehicle nodes', function (): void {
    $this->seed([ShopSettingsSeeder::class, StaticPageContentSeeder::class, HomepageContentSeeder::class]);
    $public = p5pPublicVehicleFixture('Navigation Public');
    $emptyMake = VehicleMake::factory()->create(['title' => 'Navigation Empty Make', 'slug' => 'navigation-empty-make']);
    $emptyModel = VehicleModel::factory()->forMake($public['make'])->create([
        'title' => 'Navigation Empty Model',
        'slug' => 'navigation-empty-model',
    ]);

    $this->get(route('catalog.index'))
        ->assertOk()
        ->assertSee($public['make']->title)
        ->assertDontSee($emptyMake->title);
    $this->get(route('home'))
        ->assertOk()
        ->assertSee($public['make']->title)
        ->assertDontSee($emptyMake->title);
    $this->getJson(route('storefront.vehicle-makes.models', $public['make']->slug))
        ->assertOk()
        ->assertJsonFragment(['title' => $public['model']->title, 'slug' => $public['model']->slug])
        ->assertJsonMissing(['title' => $emptyModel->title]);
    $this->getJson(route('storefront.vehicle-makes.models', $emptyMake->slug))->assertNotFound();

    $public['product']->update(['status' => ProductStatus::Archived]);
    $this->get(route('home'))->assertOk()->assertDontSee($public['make']->title);

    $public['product']->update(['status' => ProductStatus::Active]);
    $this->get(route('home'))->assertOk()->assertSee($public['make']->title);
});

test('catalog vehicle search excludes empty nodes and counts only public generations', function (): void {
    $public = p5pPublicVehicleFixture('Search Visibility');
    $emptyModel = VehicleModel::factory()->forMake($public['make'])->create([
        'title' => 'Search Visibility Empty Model',
        'slug' => 'search-visibility-empty-model',
    ]);
    $emptyGeneration = VehicleGeneration::factory()->forVehicleModel($public['model'])->create([
        'title' => 'Search Visibility Empty Generation',
        'slug' => 'search-visibility-empty-generation',
    ]);

    $response = $this->get(route('catalog.index', ['q' => 'Search Visibility']))->assertOk();
    $models = $response->viewData('vehicleModels');
    $generations = $response->viewData('vehicleGenerations');

    expect($response->viewData('vehicleMakes')->pluck('title'))->toContain($public['make']->title)
        ->and($models->pluck('model_title'))->toContain($public['model']->title)->not->toContain($emptyModel->title)
        ->and($models->firstWhere('model_title', $public['model']->title)['generation_count'] ?? null)->toBe(1)
        ->and($generations->pluck('title'))->toContain($public['generation']->title)->not->toContain($emptyGeneration->title);
});

test('vehicle model cards use the first real public generation image in deterministic order', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Image Make', 'slug' => 'image-make']);
    $model = VehicleModel::factory()->forMake($make)->create([
        'title' => 'Image Model',
        'slug' => 'image-model',
        'position' => 1,
    ]);
    $publish = function (VehicleGeneration $generation, string $title): void {
        $product = Product::factory()->withDefaultVariant()->create(['title' => $title]);
        ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
    };

    $withoutImage = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'No image',
        'image' => null,
        'position' => 0,
    ]);
    $publish($withoutImage, 'No image public product');

    VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'Private image',
        'image' => 'https://cdn.example.test/private-model.jpg',
        'position' => 1,
    ]);

    $missingFile = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'Missing file',
        'image' => 'uploads/vehicles/generations/missing.jpg',
        'position' => 2,
    ]);
    $publish($missingFile, 'Missing image public product');

    $expected = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'A deterministic image',
        'years_label' => '2019–2020',
        'body' => 'sedan',
        'image' => 'https://cdn.example.test/first-public-model.jpg',
        'position' => 10,
    ]);
    $publish($expected, 'Expected image public product');

    $later = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'B deterministic image',
        'years_label' => '2018–2019',
        'body' => 'hatchback',
        'image' => 'https://cdn.example.test/later-public-model.jpg',
        'position' => 10,
    ]);
    $publish($later, 'Later image public product');

    $fallbackModel = VehicleModel::factory()->forMake($make)->create([
        'title' => 'Fallback Model',
        'slug' => 'fallback-model',
        'position' => 2,
    ]);
    $fallbackGeneration = VehicleGeneration::factory()->forVehicleModel($fallbackModel)->create([
        'title' => 'Fallback generation',
        'image' => null,
    ]);
    $publish($fallbackGeneration, 'Fallback model public product');

    $makeResponse = $this->get(route('catalog.make', $make->slug))->assertOk()
        ->assertSee('https://cdn.example.test/first-public-model.jpg', false)
        ->assertDontSee('https://cdn.example.test/later-public-model.jpg', false)
        ->assertDontSee('https://cdn.example.test/private-model.jpg', false);

    expect($makeResponse->viewData('modelImages')->get($model->getKey()))
        ->toBe('https://cdn.example.test/first-public-model.jpg')
        ->and($makeResponse->viewData('modelImages')->has($fallbackModel->getKey()))->toBeFalse();

    $otherModelResponse = $this->get(route('catalog.model', [$make->slug, $fallbackModel->slug]))
        ->assertOk()
        ->assertSee('https://cdn.example.test/first-public-model.jpg', false);
    expect($otherModelResponse->viewData('otherModelImages')->get($model->getKey()))
        ->toBe('https://cdn.example.test/first-public-model.jpg');

    $searchResponse = $this->get(route('catalog.index', ['q' => 'Image Model']))
        ->assertOk()
        ->assertSee('https://cdn.example.test/first-public-model.jpg', false);
    expect($searchResponse->viewData('vehicleModels')->firstWhere('model_title', 'Image Model')['image'] ?? null)
        ->toBe('https://cdn.example.test/first-public-model.jpg');
});

test('sitemap uses public vehicle visibility while noindex affects sitemap only and zero price remains public', function (): void {
    $indexed = p5pPublicVehicleFixture('Sitemap Indexed', [], ['price' => 0]);
    $noindex = p5pPublicVehicleFixture('Sitemap Noindex');
    $noindex['model']->update(['noindex' => true]);
    $emptyMake = VehicleMake::factory()->create([
        'title' => 'Sitemap Empty Make',
        'slug' => 'sitemap-empty-make',
        'noindex' => false,
    ]);

    $this->get(route('catalog.model', [$noindex['make']->slug, $noindex['model']->slug]))->assertOk();

    $sitemap = $this->get(route('sitemap'))->assertOk();
    $sitemap->assertSee(e(route('catalog.make', $indexed['make']->slug)), false)
        ->assertSee(e(route('catalog.model', [$indexed['make']->slug, $indexed['model']->slug])), false)
        ->assertSee(e(route('catalog.generation', [$indexed['make']->slug, $indexed['model']->slug, $indexed['generation']->slug])), false)
        ->assertDontSee($emptyMake->slug, false)
        ->assertDontSee(e(route('catalog.model', [$noindex['make']->slug, $noindex['model']->slug])), false)
        ->assertDontSee(e(route('catalog.generation', [$noindex['make']->slug, $noindex['model']->slug, $noindex['generation']->slug])), false);
});

test('PublicCatalogCache returns only public Makes and invalidates only its vehicle navigation key', function (): void {
    $public = p5pPublicVehicleFixture('Cache Public');
    $empty = VehicleMake::factory()->create(['title' => 'Cache Empty', 'slug' => 'cache-empty']);
    $cache = app(PublicCatalogCache::class);

    expect($cache->activeMakes()->pluck('id')->all())->toBe([$public['make']->getKey()]);

    Cache::put('public_catalog:public_make_ids:v3', [$empty->getKey()], 1800);
    Cache::put('unrelated:test:key', 'keep-me', 1800);
    $cache->invalidateVehicleNavigation();

    expect(Cache::has('public_catalog:public_make_ids:v3'))->toBeFalse()
        ->and(Cache::get('unrelated:test:key'))->toBe('keep-me');
});
