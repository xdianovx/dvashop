<?php

use App\Exceptions\Catalog\CatalogCategoryStructureConflictException;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

test('product catalog seeder rejects a legacy category location and rolls back partial structure', function () {
    $root = ProductCategory::factory()->create([
        'title' => 'Кузовные детали',
        'slug' => 'kuzovnye-detali',
    ]);
    $legacyThresholds = ProductCategory::factory()->forParent($root)->create([
        'title' => 'Пороги',
        'slug' => 'porogi',
    ]);
    $product = Product::factory()->forCategory($legacyThresholds)->create()->fresh();
    $productAttributes = $product->getAttributes();

    expect(fn () => $this->seed(ProductCatalogSeeder::class))
        ->toThrow(CatalogCategoryStructureConflictException::class);

    expect(ProductCategory::withTrashed()->count())->toBe(2)
        ->and($legacyThresholds->fresh())->not->toBeNull()
        ->and($legacyThresholds->fresh()->parent_id)->toBe($root->id)
        ->and($legacyThresholds->fresh()->full_slug)->toBe('kuzovnye-detali/porogi')
        ->and(ProductCategory::query()->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova')->exists())->toBeFalse()
        ->and(ProductCategory::query()->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova/porogi')->exists())->toBeFalse()
        ->and(ProductCategory::withTrashed()->where('title', 'Пороги')->count())->toBe(1)
        ->and($product->fresh()->getAttributes())->toBe($productAttributes);
});

test('product catalog seeder rejects an expected path occupied by an invalid slug', function () {
    $categoryId = DB::table('product_categories')->insertGetId([
        'parent_id' => null,
        'title' => 'Повреждённая категория',
        'slug' => 'wrong-slug',
        'full_slug' => 'kuzovnye-detali',
        'depth' => 0,
        'position' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $this->seed(ProductCatalogSeeder::class))
        ->toThrow(CatalogCategoryStructureConflictException::class);

    expect(ProductCategory::withTrashed()->count())->toBe(1)
        ->and(ProductCategory::query()->findOrFail($categoryId)->slug)->toBe('wrong-slug');
});
