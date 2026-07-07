<?php

use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Services\Media\DefaultProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function defaultImageTestCategory(string $title, ?ProductCategory $parent = null): ProductCategory
{
    return ProductCategory::factory()->create([
        'parent_id' => $parent?->getKey(),
        'title' => $title,
        'slug' => null,
    ])->refresh();
}

test('DefaultProductImageService resolves default images by category path', function (string $parentTitle, string $title, string $expectedKey) {
    $parent = $parentTitle === '' ? null : defaultImageTestCategory($parentTitle);
    $category = defaultImageTestCategory($title, $parent);

    $default = app(DefaultProductImageService::class)->forCategory($category);

    expect($default)->not->toBeNull()
        ->and($default['key'])->toBe($expectedKey)
        ->and($default['path'])->toStartWith('img/products_default/'.$expectedKey.'.')
        ->and($default['url'])->toContain('img/products_default/'.$expectedKey.'.')
        ->and(is_file($default['absolute_path']))->toBeTrue();
})->with([
    'porog' => ['', 'Порог', 'porog'],
    'arka vnutrenniaia universalnaia' => ['Арка', 'Внутренняя универсальная', 'arka-vnutrenniaia-universalnaia'],
    'penka zadnei dveri' => ['Пенка', 'Задней двери', 'penka-zadnei-dveri'],
    'lonzeron' => ['', 'Лонжерон', 'lonzeron'],
]);

test('DefaultProductImageService returns null when mapped file is absent or category is unknown', function () {
    $category = defaultImageTestCategory('Неизвестная деталь');

    expect(app(DefaultProductImageService::class)->forCategory($category))->toBeNull()
        ->and(app(DefaultProductImageService::class)->findByKey('missing-default-file'))->toBeNull();
});

test('default ProductImage reference does not delete physical default file', function () {
    $category = defaultImageTestCategory('Порог');
    $default = app(DefaultProductImageService::class)->forCategory($category);

    $image = ProductImage::factory()->create([
        'disk' => DefaultProductImageService::DISK,
        'path' => $default['path'],
        'source_type' => 'default',
        'is_default' => true,
        'is_main' => true,
        'is_visible' => true,
    ]);

    $image->delete();

    expect(is_file($default['absolute_path']))->toBeTrue();
});
