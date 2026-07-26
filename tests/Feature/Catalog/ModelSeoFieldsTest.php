<?php

use App\Filament\Schemas\SeoSchema;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('Seo migration adds the complete field set to every catalog entity', function () {
    $columns = [
        'meta_title',
        'meta_description',
        'seo_h1',
        'seo_text',
        'canonical_url',
        'noindex',
        'og_title',
        'og_description',
        'og_image',
    ];

    foreach (['products', 'product_categories', 'part_types', 'vehicle_makes', 'vehicle_models', 'vehicle_generations'] as $table) {
        expect(Schema::hasColumns($table, $columns))->toBeTrue($table);
    }
});

test('Seo fields are mass assignable and noindex is boolean on every catalog model', function () {
    $attributes = [
        'seo_h1' => 'SEO H1',
        'seo_text' => 'SEO text',
        'canonical_url' => 'https://example.test/canonical',
        'noindex' => 1,
        'og_title' => 'OG title',
        'og_description' => 'OG description',
        'og_image' => 'uploads/seo/open-graph/image.webp',
    ];

    $models = [
        Product::factory()->create($attributes),
        ProductCategory::factory()->create($attributes),
        PartType::factory()->create($attributes),
        VehicleMake::factory()->create($attributes),
        VehicleModel::factory()->create($attributes),
        VehicleGeneration::factory()->create($attributes),
    ];

    foreach ($models as $model) {
        expect($model->noindex)->toBeTrue()
            ->and($model->seo_h1)->toBe('SEO H1')
            ->and($model->og_image)->toBe('uploads/seo/open-graph/image.webp');
    }
});

test('Seo schema exposes one reusable validated field set', function () {
    $fieldNames = collect(SeoSchema::fields())
        ->map(fn ($field): string => $field->getName())
        ->all();

    expect($fieldNames)->toBe([
        'meta_title',
        'seo_h1',
        'meta_description',
        'seo_text',
        'canonical_url',
        'noindex',
        'og_title',
        'og_description',
        'og_image',
    ]);
});
