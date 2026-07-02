<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cartTestVariant(array $productState = [], array $variantState = []): ProductVariant
{
    $product = Product::factory()->create(array_merge([
        'title' => 'Порог левый Toyota Camry',
        'slug' => fake()->unique()->slug(),
    ], $productState));

    return ProductVariant::factory()
        ->forProduct($product)
        ->create(array_merge([
            'title' => 'Стандарт',
            'price' => 2500,
            'is_active' => true,
        ], $variantState));
}

function currentCartToken(): string
{
    return (string) Cart::query()->firstOrFail()->token;
}

function csrfPayload(array $payload = []): array
{
    return array_merge(['_token' => 'test-csrf-token'], $payload);
}

test('guest receives cart cookie', function () {
    $response = $this->get('/cart');

    $response->assertOk();
    $response->assertCookie(CartManager::COOKIE_NAME);

    expect(Cart::query()->count())->toBe(1)
        ->and(Cart::query()->first()->user_id)->toBeNull();
});

test('product variant is added to cart', function () {
    $variant = cartTestVariant();

    $response = $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/cart/items', csrfPayload([
            'product_variant_id' => $variant->getKey(),
            'quantity' => 2,
        ]));

    $response->assertRedirect(route('cart.show'));
    $response->assertCookie(CartManager::COOKIE_NAME);

    $item = CartItem::query()->firstOrFail();

    expect(Cart::query()->count())->toBe(1)
        ->and($item->product_variant_id)->toBe($variant->getKey())
        ->and($item->quantity)->toBe(2)
        ->and($item->price_snapshot)->toBe('2500.00')
        ->and($item->title_snapshot)->toBe('Порог левый Toyota Camry — Стандарт');
});

test('adding same variant again increments quantity', function () {
    $variant = cartTestVariant();

    $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/cart/items', csrfPayload([
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ]))
        ->assertRedirect(route('cart.show'));

    $token = currentCartToken();

    $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartManager::COOKIE_NAME, $token)
        ->post('/cart/items', csrfPayload([
            'product_variant_id' => $variant->getKey(),
            'quantity' => 3,
        ]))
        ->assertRedirect(route('cart.show'));

    $item = CartItem::query()->firstOrFail();

    expect(Cart::query()->count())->toBe(1)
        ->and(CartItem::query()->count())->toBe(1)
        ->and($item->quantity)->toBe(4);
});

test('cart item quantity is updated', function () {
    $variant = cartTestVariant();

    $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/cart/items', csrfPayload([
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ]))
        ->assertRedirect(route('cart.show'));

    $token = currentCartToken();
    $item = CartItem::query()->firstOrFail();

    $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartManager::COOKIE_NAME, $token)
        ->patch('/cart/items/' . $item->getKey(), csrfPayload([
            'quantity' => 5,
        ]))
        ->assertRedirect(route('cart.show'));

    expect($item->fresh()->quantity)->toBe(5);
});

test('cart item is removed', function () {
    $variant = cartTestVariant();

    $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/cart/items', csrfPayload([
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ]))
        ->assertRedirect(route('cart.show'));

    $token = currentCartToken();
    $item = CartItem::query()->firstOrFail();

    $this
        ->withSession(['_token' => 'test-csrf-token'])
        ->withCookie(CartManager::COOKIE_NAME, $token)
        ->delete('/cart/items/' . $item->getKey(), csrfPayload())
        ->assertRedirect(route('cart.show'));

    expect(CartItem::query()->count())->toBe(0);
});

test('inactive variant cannot be added', function () {
    $variant = cartTestVariant([], ['is_active' => false]);

    $this
        ->from('/catalog')
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/cart/items', csrfPayload([
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ]))
        ->assertRedirect('/catalog')
        ->assertSessionHasErrors('product_variant_id');

    expect(CartItem::query()->count())->toBe(0);
});
