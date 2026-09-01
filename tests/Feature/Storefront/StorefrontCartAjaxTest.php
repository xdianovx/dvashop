<?php

use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function cartAjaxRequest(Cart $cart): Request
{
    return Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
}

function cartAjaxVariant(array $attributes = []): ProductVariant
{
    return ProductVariant::factory()->default()->create(array_merge([
        'price' => 2500,
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => null,
    ], $attributes));
}

test('ordinary cart add keeps redirect fallback', function (): void {
    $variant = cartAjaxVariant();

    $this->post(route('cart.items.store'), [
        'product_variant_id' => $variant->getKey(),
        'quantity' => 1,
    ])->assertRedirect(route('cart.show'));
});

test('json cart add returns server item and totals payload', function (): void {
    $variant = cartAjaxVariant();

    $this->postJson(route('cart.items.store'), [
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2,
        'price' => 1,
        'subtotal' => 1,
    ])->assertCreated()
        ->assertJsonPath('message', 'Товар добавлен в корзину.')
        ->assertJsonPath('cart.items_count', 2)
        ->assertJsonPath('cart.subtotal', 5000)
        ->assertJsonPath('item.quantity', 2)
        ->assertJsonPath('item.line_total', 5000)
        ->assertJsonStructure(['item' => ['id', 'quantity', 'line_total']]);
});

test('json repeated add returns accumulated quantity and count', function (): void {
    $variant = cartAjaxVariant();
    $cart = Cart::factory()->create();
    $cookie = [CartManager::COOKIE_NAME => $cart->token];

    $this->withCredentials()->withCookies($cookie)->postJson(route('cart.items.store'), [
        'product_variant_id' => $variant->getKey(),
        'quantity' => 1,
    ])->assertCreated()->assertJsonPath('item.quantity', 1);

    $this->withCredentials()->withCookies($cookie)->postJson(route('cart.items.store'), [
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2,
    ])->assertCreated()
        ->assertJsonPath('cart.items_count', 3)
        ->assertJsonPath('cart.subtotal', 7500)
        ->assertJsonPath('item.quantity', 3)
        ->assertJsonPath('item.line_total', 7500);

    expect($cart->items()->sole()->quantity)->toBe(3);
});

test('json cart add rejects zero price out of stock and unavailable variants', function (): void {
    $zeroProduct = Product::factory()->create(['price' => 0]);
    $zeroPrice = ProductVariant::factory()->forProduct($zeroProduct)->default()->create([
        'price' => 0,
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => null,
    ]);
    $outOfStock = cartAjaxVariant([
        'stock_status' => StockStatus::OutOfStock,
        'stock_quantity' => 0,
    ]);
    $inactive = ProductVariant::factory()->inactive()->create([
        'price' => 2500,
        'stock_quantity' => null,
    ]);

    $this->postJson(route('cart.items.store'), [
        'product_variant_id' => $zeroPrice->getKey(),
        'quantity' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');

    $this->postJson(route('cart.items.store'), [
        'product_variant_id' => $outOfStock->getKey(),
        'quantity' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

    $this->postJson(route('cart.items.store'), [
        'product_variant_id' => $inactive->getKey(),
        'quantity' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');

    $this->postJson(route('cart.items.store'), [
        'product_variant_id' => 999999,
        'quantity' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');
});

test('json cart mutations return authoritative totals and changed row identifiers', function (): void {
    $first = cartAjaxVariant(['price' => 1200]);
    $second = cartAjaxVariant(['price' => 800]);
    $cart = Cart::factory()->create();
    $manager = app(CartManager::class);
    $firstItem = $manager->addItem(cartAjaxRequest($cart), $first->getKey(), 1);
    $secondItem = $manager->addItem(cartAjaxRequest($cart), $second->getKey(), 1);
    $cookie = [CartManager::COOKIE_NAME => $cart->token];

    $this->withCredentials()->withCookies($cookie)->patchJson(route('cart.items.update', $firstItem), [
        'quantity' => 2,
    ])->assertOk()
        ->assertJsonPath('cart.items_count', 3)
        ->assertJsonPath('cart.subtotal', 3200)
        ->assertJsonPath('item.id', $firstItem->getKey())
        ->assertJsonPath('item.quantity', 2)
        ->assertJsonPath('item.line_total', 2400);

    $this->withCredentials()->withCookies($cookie)->deleteJson(route('cart.items.destroy', $secondItem))
        ->assertOk()
        ->assertJsonPath('cart.items_count', 2)
        ->assertJsonPath('cart.subtotal', 2400)
        ->assertJsonPath('removed_id', $secondItem->getKey());

    $this->withCredentials()->withCookies($cookie)->deleteJson(route('cart.clear'))
        ->assertOk()
        ->assertJsonPath('cart.items_count', 0)
        ->assertJsonPath('cart.subtotal', 0);
});

test('storefront header renders existing cart count', function (): void {
    $variant = cartAjaxVariant();
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(cartAjaxRequest($cart), $variant->getKey(), 9);

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertSee('aria-label="Корзина, товаров: 9"', false)
        ->assertSee('data-cart-count', false)
        ->assertSee('>9</span>', false);
});

test('storefront header summary does not create a cart or cookie without token', function (): void {
    expect(Cart::query()->count())->toBe(0);

    $this->get(route('catalog.index'))
        ->assertOk()
        ->assertCookieMissing(CartManager::COOKIE_NAME)
        ->assertSee('aria-label="Корзина, товаров: 0"', false)
        ->assertSee('data-cart-count', false);

    expect(Cart::query()->count())->toBe(0);
});

test('cart progressive enhancement hooks badge toast and mutations into approved views', function (): void {
    $header = file_get_contents(resource_path('views/components/header.blade.php'));
    $toast = file_get_contents(resource_path('views/components/storefront-toast.blade.php'));
    $card = file_get_contents(resource_path('views/components/product-card.blade.php'));
    $part = file_get_contents(resource_path('views/part.blade.php'));
    $item = file_get_contents(resource_path('views/components/cart-item.blade.php'));
    $summary = file_get_contents(resource_path('views/components/cart-summary.blade.php'));
    $cart = file_get_contents(resource_path('views/cart.blade.php'));
    $scripts = file_get_contents(resource_path('js/app.js'));
    $styles = file_get_contents(resource_path('scss/_header.scss'));

    expect($header)
        ->toContain('header__cart-badge', 'data-cart-count', 'Корзина, товаров:')
        ->and($toast)
        ->toContain('data-storefront-toast', 'aria-live="polite"', 'Перейти в корзину', 'data-storefront-toast-close')
        ->and($card)
        ->toContain('data-cart-add')
        ->and($part)
        ->toContain('data-cart-add', 'data-cart-button-label')
        ->and($item)
        ->toContain('data-cart-update', 'data-cart-remove', 'data-cart-item-line-total')
        ->and($summary)
        ->toContain('data-cart-subtotal', 'data-cart-total')
        ->and($cart)
        ->toContain('data-cart-clear')
        ->and($scripts)
        ->toContain("Accept: 'application/json'", "'X-Requested-With': 'XMLHttpRequest'", 'textContent', 'header__cart-badge--pulse')
        ->and($styles)
        ->toContain('@keyframes header-cart-badge-pulse', '@media (prefers-reduced-motion: reduce)');
});
