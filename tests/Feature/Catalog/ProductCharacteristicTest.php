<?php

use App\Models\Product;
use App\Models\ProductCharacteristic;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ProductCharacteristic belongs to product sorts by position and supports visible scope', function () {
    $product = Product::factory()->create();
    ProductCharacteristic::factory()->create([
        'product_id' => $product->getKey(),
        'name' => 'Скрытая',
        'value' => 'Нет',
        'source_type' => ProductCharacteristic::SOURCE_IMPORT,
        'is_visible' => false,
        'position' => 5,
    ]);
    ProductCharacteristic::factory()->create([
        'product_id' => $product->getKey(),
        'name' => 'Материал',
        'value' => 'Сталь',
        'unit' => null,
        'source_type' => ProductCharacteristic::SOURCE_MANUAL,
        'is_visible' => true,
        'position' => 20,
    ]);
    ProductCharacteristic::factory()->create([
        'product_id' => $product->getKey(),
        'name' => 'Толщина',
        'value' => '1',
        'unit' => 'мм',
        'source_type' => ProductCharacteristic::SOURCE_DEFAULT,
        'is_visible' => true,
        'position' => 10,
    ]);

    expect($product->characteristics()->pluck('name')->all())->toBe(['Скрытая', 'Толщина', 'Материал'])
        ->and($product->characteristics()->visible()->pluck('name')->all())->toBe(['Толщина', 'Материал'])
        ->and($product->characteristics()->where('name', 'Скрытая')->firstOrFail()->is_visible)->toBeFalse()
        ->and($product->characteristics()->where('name', 'Толщина')->firstOrFail()->source_type)->toBe(ProductCharacteristic::SOURCE_DEFAULT);
});
