<?php

use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product catalog seeder creates the intended storefront category tree only', function () {
    $this->seed(ProductCatalogSeeder::class);

    $expectedPaths = [
        'kuzovnye-detali' => ['Кузовные детали', 0],
        'kuzovnye-detali/remontnye-elementy-kuzova' => ['Ремонтные элементы кузова', 1],
        'kuzovnye-detali/remontnye-elementy-kuzova/porogi' => ['Пороги', 2],
        'kuzovnye-detali/remontnye-elementy-kuzova/arki' => ['Арки', 2],
        'kuzovnye-detali/remontnye-elementy-kuzova/lonzherony' => ['Лонжероны', 2],
        'kuzovnye-detali/remontnye-elementy-kuzova/remkomplekty-pola' => ['Ремкомплекты пола', 2],
        'kuzovnye-detali/remontnye-elementy-kuzova/zaglushki' => ['Заглушки', 2],
        'kuzovnye-detali/remontnye-elementy-kuzova/usiliteli' => ['Усилители', 2],
        'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki' => ['Пенные вставки', 2],
    ];

    foreach ($expectedPaths as $fullSlug => [$title, $depth]) {
        $category = ProductCategory::query()->where('full_slug', $fullSlug)->first();

        expect($category)->not->toBeNull()
            ->and($category->title)->toBe($title)
            ->and($category->depth)->toBe($depth);
    }

    expect(ProductCategory::query()->where('depth', 2)->count())->toBe(7)
        ->and(ProductCategory::query()->whereIn('full_slug', [
            'arka/zadniaia',
            'penka/zadnei-dveri',
        ])->exists())->toBeFalse();
});

test('product catalog seeder is idempotent and preserves existing categories and products', function () {
    $existingCategory = ProductCategory::factory()->create(['title' => 'Автохимия']);
    $product = Product::factory()->forCategory($existingCategory)->create()->fresh();
    $productAttributes = $product->getAttributes();

    $this->seed(ProductCatalogSeeder::class);
    $countAfterFirstRun = ProductCategory::withTrashed()->count();
    $this->seed(ProductCatalogSeeder::class);

    expect(ProductCategory::withTrashed()->count())->toBe($countAfterFirstRun)
        ->and($existingCategory->fresh())->not->toBeNull()
        ->and($product->fresh()->getAttributes())->toBe($productAttributes);
});
