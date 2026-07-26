<?php

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Seo\SeoMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

test('SeoMetadataService resolves product fallbacks and a safe main image URL', function () {
    $product = Product::factory()->create([
        'title' => 'Порог кузова',
        'short_description' => '<b>Надёжный</b> оцинкованный порог',
        'description' => 'Описание, которое не должно иметь приоритет.',
    ]);
    Storage::disk('public')->put('uploads/products/main.webp', 'image');
    ProductImage::withoutEvents(fn (): ProductImage => ProductImage::factory()->for($product)->create([
        'disk' => 'public',
        'path' => 'uploads/products/main.webp',
        'source_type' => ProductImage::SOURCE_MANUAL,
        'is_main' => true,
        'is_visible' => true,
    ]));

    $metadata = app(SeoMetadataService::class)->resolve($product->refresh());

    expect($metadata['meta_title'])->toBe('Порог кузова')
        ->and($metadata['h1'])->toBe('Порог кузова')
        ->and($metadata['meta_description'])->toBe('Надёжный оцинкованный порог')
        ->and($metadata['og_title'])->toBe('Порог кузова')
        ->and($metadata['og_description'])->toBe('Надёжный оцинкованный порог')
        ->and($metadata['og_image'])->toContain('uploads/products/main.webp')
        ->and($metadata['noindex'])->toBeFalse();
});

test('SeoMetadataService gives explicit fields priority and resolves uploaded OG image', function () {
    Storage::disk('public')->put('uploads/seo/open-graph/category.webp', 'image');
    $category = ProductCategory::factory()->create([
        'title' => 'Пороги',
        'meta_title' => 'Купить пороги',
        'meta_description' => 'Явное описание',
        'seo_h1' => 'Пороги для автомобилей',
        'seo_text' => '<p>Расширенный SEO-текст</p>',
        'canonical_url' => 'https://example.test/porogi',
        'noindex' => true,
        'og_title' => 'Пороги — Open Graph',
        'og_description' => 'Описание для социальных сетей',
        'og_image' => 'uploads/seo/open-graph/category.webp',
    ]);

    $metadata = app(SeoMetadataService::class)->resolve($category);

    expect($metadata)->toMatchArray([
        'meta_title' => 'Купить пороги',
        'meta_description' => 'Явное описание',
        'h1' => 'Пороги для автомобилей',
        'seo_text' => '<p>Расширенный SEO-текст</p>',
        'canonical_url' => 'https://example.test/porogi',
        'noindex' => true,
        'og_title' => 'Пороги — Open Graph',
        'og_description' => 'Описание для социальных сетей',
    ])->and($metadata['og_image'])->toContain('uploads/seo/open-graph/category.webp');
});

test('SeoMetadataService resolves category and part type descriptions without generated copy', function () {
    $category = ProductCategory::factory()->create([
        'title' => 'Кузовные детали',
        'seo_text' => '<p>Каталог кузовных деталей.</p>',
    ]);
    $parentPartType = PartType::factory()->create(['title' => 'Кузов']);
    $partType = PartType::factory()->childOf($parentPartType)->create([
        'title' => 'Арка',
        'seo_text' => 'Описание типа детали.',
    ]);
    $service = app(SeoMetadataService::class);

    expect($service->resolve($category))
        ->toMatchArray([
            'meta_title' => 'Кузовные детали',
            'meta_description' => 'Каталог кузовных деталей.',
            'h1' => 'Кузовные детали',
        ])
        ->and($service->resolve($partType))
        ->toMatchArray([
            'meta_title' => 'Кузов / Арка',
            'meta_description' => 'Описание типа детали.',
            'h1' => 'Кузов / Арка',
        ]);
});

test('SeoMetadataService resolves vehicle make model and generation display titles', function () {
    $make = VehicleMake::factory()->create(['title' => 'Toyota']);
    $model = VehicleModel::factory()->for($make, 'make')->create(['title' => 'Camry']);
    $generation = VehicleGeneration::factory()->for($model, 'model')->create([
        'title' => 'XV50',
        'years_label' => '2011–2017',
        'body' => 'седан',
        'seo_text' => 'Детали для выбранного поколения.',
    ]);
    $service = app(SeoMetadataService::class);

    expect($service->resolve($make)['meta_title'])->toBe('Toyota')
        ->and($service->resolve($model)['meta_title'])->toBe('Toyota Camry')
        ->and($service->resolve($generation))
        ->toMatchArray([
            'meta_title' => 'Toyota Camry XV50 2011–2017 седан',
            'meta_description' => 'Детали для выбранного поколения.',
            'h1' => 'Toyota Camry XV50 2011–2017 седан',
        ]);
});
