<?php

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function requestForCart(Cart $cart): Request
{
    return Request::create('/', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
}

test('CartSnapshot add item stores catalog snapshots including options and image', function () {
    $product = Product::factory()->create([
        'title' => 'Порог Mazda 6',
        'sku' => 'PRODUCT-42',
        'price' => 3100,
        'old_price' => 3500,
    ]);
    ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'https://cdn.example.test/products/mazda-6.webp',
    ]);
    $variant = ProductVariant::factory()->forProduct($product)->create([
        'sku' => 'VARIANT-42',
        'title' => 'Правый',
        'options' => [
            'material' => ['group' => 'Материал', 'value' => 'Оцинковка'],
            'side' => ['group' => 'Сторона', 'value' => 'Правая'],
        ],
        'price' => 2990,
        'old_price' => 3290,
    ]);
    $cart = Cart::factory()->create();

    $item = app(CartManager::class)->addItem(requestForCart($cart), $variant->getKey(), 2);

    expect($item->product_id)->toBe($product->getKey())
        ->and($item->product_variant_id)->toBe($variant->getKey())
        ->and($item->sku_snapshot)->toBe('VARIANT-42')
        ->and($item->title_snapshot)->toBe('Порог Mazda 6 — Правый')
        ->and($item->options_snapshot['material']['value'])->toBe('Оцинковка')
        ->and($item->optionSummary())->toContain('Материал: Оцинковка')
        ->and($item->image_snapshot)->toBe('https://cdn.example.test/products/mazda-6.webp')
        ->and($item->price_snapshot)->toBe('2990.00')
        ->and($item->old_price_snapshot)->toBe('3290.00')
        ->and($item->lineTotal())->toBe(5980.0);
});

test('CartSnapshot quantity updates keep snapshots and totals use snapshot price', function () {
    $variant = ProductVariant::factory()->create(['price' => 1750]);
    $cart = Cart::factory()->create();
    $request = requestForCart($cart);
    $manager = app(CartManager::class);
    $item = $manager->addItem($request, $variant->getKey());

    $variant->update(['price' => 9999]);
    $updated = $manager->updateQuantity($request, $item, 3);
    $totals = $manager->totals($cart->refresh());

    expect($updated->price_snapshot)->toBe('1750.00')
        ->and($updated->quantity)->toBe(3)
        ->and($totals)->toMatchArray(['items_count' => 3, 'subtotal' => 5250.0]);
});

test('CartSnapshot remains readable after product and variant are deleted', function () {
    $variant = ProductVariant::factory()->create([
        'options' => ['side' => ['group' => 'Сторона', 'value' => 'Левая']],
        'price' => 2100,
    ]);
    $product = $variant->product;
    $cart = Cart::factory()->create();
    $item = app(CartManager::class)->addItem(requestForCart($cart), $variant->getKey());
    $title = $item->title_snapshot;

    $product->forceDelete();
    $item->refresh();

    expect($item->product_id)->toBeNull()
        ->and($item->product_variant_id)->toBeNull()
        ->and($item->title_snapshot)->toBe($title)
        ->and($item->optionSummary())->toContain('Сторона: Левая')
        ->and($item->lineTotal())->toBe(2100.0);
});
