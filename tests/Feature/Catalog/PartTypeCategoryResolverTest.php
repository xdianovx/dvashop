<?php

use App\Exceptions\Catalog\RequiredCatalogCategoryMissingException;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Services\Catalog\PartTypeCategoryResolver;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);
});

test('resolver maps known part type paths to storefront categories', function (string $partTypePath, string $categoryPath) {
    $resolution = app(PartTypeCategoryResolver::class)->resolve($partTypePath);

    expect($resolution->category->full_slug)->toBe($categoryPath)
        ->and($resolution->usedFallback)->toBeFalse();
})->with([
    'threshold' => ['porog', 'kuzovnye-detali/remontnye-elementy-kuzova/porogi'],
    'rear arch' => ['arka/zadniaia', 'kuzovnye-detali/remontnye-elementy-kuzova/arki'],
    'universal inner arch' => ['arka/vnutrenniaia-universalnaia', 'kuzovnye-detali/remontnye-elementy-kuzova/arki'],
    'rear door foam' => ['penka/zadnei-dveri', 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki'],
    'longeron' => ['lonzheron', 'kuzovnye-detali/remontnye-elementy-kuzova/lonzherony'],
    'floor repair panel' => ['remkomplekt-pola', 'kuzovnye-detali/remontnye-elementy-kuzova/remkomplekty-pola'],
    'end cap' => ['tortsevaia-zaglushka', 'kuzovnye-detali/remontnye-elementy-kuzova/zaglushki'],
    'threshold connector' => ['usilitel/soedinitel-porogov', 'kuzovnye-detali/remontnye-elementy-kuzova/usiliteli'],
]);

test('resolver returns marked fallback for an unknown part type', function () {
    $resolution = app(PartTypeCategoryResolver::class)->resolve('neizvestnaia-detal');

    expect($resolution->category->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova')
        ->and($resolution->usedFallback)->toBeTrue();
});

test('resolver accepts a part type model and a normalized string', function () {
    $partType = PartType::factory()->create(['title' => 'Порог']);
    $resolver = app(PartTypeCategoryResolver::class);

    expect($resolver->resolve($partType)->category->full_slug)
        ->toBe('kuzovnye-detali/remontnye-elementy-kuzova/porogi')
        ->and($resolver->resolve('porog')->category->full_slug)
        ->toBe('kuzovnye-detali/remontnye-elementy-kuzova/porogi');
});

test('resolver does not create a missing required category', function () {
    $category = ProductCategory::query()
        ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova/porogi')
        ->firstOrFail();
    $category->forceDelete();
    $countBefore = ProductCategory::withTrashed()->count();

    try {
        app(PartTypeCategoryResolver::class)->resolve('porog');
    } finally {
        expect(ProductCategory::withTrashed()->count())->toBe($countBefore);
    }
})->throws(RequiredCatalogCategoryMissingException::class, 'не найдена');

test('resolver reuses a category query within its local cache', function () {
    $queries = 0;
    $categoryPath = 'kuzovnye-detali/remontnye-elementy-kuzova/arki';

    DB::listen(function (QueryExecuted $query) use (&$queries, $categoryPath): void {
        if (str_contains($query->sql, 'product_categories') && in_array($categoryPath, $query->bindings, true)) {
            $queries++;
        }
    });

    $resolver = new PartTypeCategoryResolver;
    $resolver->resolve('arka/zadniaia');
    $resolver->resolve('arka/peredniaia');
    $resolver->resolve('arka/vnutrenniaia-universalnaia');

    expect($queries)->toBe(1);
});

test('resolver cache is instance local and can be reset explicitly', function () {
    $categoryPath = 'kuzovnye-detali/remontnye-elementy-kuzova/porogi';
    $category = ProductCategory::query()->where('full_slug', $categoryPath)->firstOrFail();
    $firstResolver = new PartTypeCategoryResolver;
    $firstResolver->resolve('porog');
    $category->delete();

    expect(fn () => (new PartTypeCategoryResolver)->resolve('porog'))
        ->toThrow(RequiredCatalogCategoryMissingException::class);

    $firstResolver->resetLocalCache();

    expect(fn () => $firstResolver->resolve('porog'))
        ->toThrow(RequiredCatalogCategoryMissingException::class);
});
