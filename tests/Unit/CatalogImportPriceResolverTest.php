<?php

use App\Models\PartType;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Import\CatalogImportPriceResolver;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([ProductCatalogSeeder::class, PartTypeSeeder::class, ProductOptionSeeder::class]);
    app(CatalogConfigurations::class)->install(base_path('tests/Fixtures/catalog-configurations.csv'));
});

test('import reference prices are derived from installed CSV rather than hardcoded per-type amounts', function (): void {
    $resolver = app(CatalogImportPriceResolver::class);
    $matrix = app(CatalogConfigurations::class);
    foreach (PartType::query()->get() as $type) {
        $rows = $matrix->rows($type->id);
        if ($rows->isEmpty()) {
            continue;
        }
        expect($resolver->resolve($type))->toBe([
            'price' => $matrix->basePrice($type->id),
            'old_price' => null,
        ]);
    }
});

test('a missing CSV group never falls back to a historical type price', function (): void {
    $resolver = app(CatalogImportPriceResolver::class);
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    \Illuminate\Support\Facades\DB::table('catalog_configurations')->where('part_type_id', $type->id)->update(['is_active' => false]);
    app(CatalogConfigurations::class)->clear();
    expect(fn () => $resolver->resolve($type))->toThrow(ValidationException::class, 'Не установлены цены CSV');
});
