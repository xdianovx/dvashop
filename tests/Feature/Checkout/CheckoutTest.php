<?php

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function checkoutVariant(array $productState = [], array $variantState = []): ProductVariant
{
    $product = Product::factory()->create(array_merge([
        'title' => 'Порог правый Toyota Camry',
        'slug' => fake()->unique()->slug(),
        'sku' => 'PROD-SKU-1',
    ], $productState));

    return ProductVariant::factory()
        ->forProduct($product)
        ->create(array_merge([
            'title' => 'Оцинковка',
            'sku' => 'VAR-SKU-1',
            'price' => 3500,
            'is_active' => true,
        ], $variantState));
}

function checkoutCartWithItem(int $quantity = 2, float $price = 1750.0): array
{
    $cart = Cart::factory()->create();
    $variant = checkoutVariant();

    $item = CartItem::factory()
        ->forCart($cart)
        ->forVariant($variant)
        ->create([
            'quantity' => $quantity,
            'price_snapshot' => $price,
            'title_snapshot' => 'Порог правый Toyota Camry — Оцинковка',
        ]);

    return [$cart, $variant, $item];
}

function checkoutPayload(array $payload = []): array
{
    return array_merge([
        '_token' => 'test-csrf-token',
        'name' => 'Иван Иванов',
        'phone' => '+79990000000',
        'email' => 'ivan@example.com',
        'city' => 'Москва',
        'address' => 'Улица, дом',
        'comment' => 'Позвонить заранее',
    ], $payload);
}

test('empty cart cannot be checked out', function () {
    $this
        ->from('/checkout')
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/checkout', checkoutPayload())
        ->assertRedirect('/checkout')
        ->assertSessionHasErrors('cart');

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0);
});

test('order is created from cart', function () {
    [$cart] = checkoutCartWithItem(quantity: 2, price: 1750.0);

    $response = $this
        ->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/checkout', checkoutPayload());

    $response->assertRedirect(route('checkout.show'));
    $response->assertSessionHas('order_created');
    $response->assertCookie(CartManager::COOKIE_NAME);

    $order = Order::query()->firstOrFail();

    expect($order->number)->toStartWith('DVS-')
        ->and($order->cart_id)->toBe($cart->getKey())
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->customer_name)->toBe('Иван Иванов')
        ->and($order->customer_phone)->toBe('+79990000000')
        ->and($order->customer_email)->toBe('ivan@example.com');
});

test('order items are copied as snapshots', function () {
    [$cart, $variant] = checkoutCartWithItem(quantity: 3, price: 1200.5);

    $this
        ->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/checkout', checkoutPayload())
        ->assertRedirect(route('checkout.show'));

    $orderItem = OrderItem::query()->firstOrFail();

    expect($orderItem->product_id)->toBe($variant->product_id)
        ->and($orderItem->product_variant_id)->toBe($variant->getKey())
        ->and($orderItem->title)->toBe('Порог правый Toyota Camry — Оцинковка')
        ->and($orderItem->sku)->toBe('VAR-SKU-1')
        ->and($orderItem->quantity)->toBe(3)
        ->and($orderItem->price)->toBe('1200.50')
        ->and($orderItem->total)->toBe('3601.50');
});

test('cart becomes ordered and a new active cart is created', function () {
    [$cart] = checkoutCartWithItem();

    $this
        ->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/checkout', checkoutPayload())
        ->assertRedirect(route('checkout.show'));

    expect($cart->fresh()->status)->toBe(CartStatus::Ordered)
        ->and(Cart::query()->where('status', CartStatus::Active->value)->count())->toBe(1);
});

test('order totals are calculated from cart snapshots', function () {
    [$cart] = checkoutCartWithItem(quantity: 2, price: 1999.99);

    $this
        ->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->withSession(['_token' => 'test-csrf-token'])
        ->post('/checkout', checkoutPayload())
        ->assertRedirect(route('checkout.show'));

    $order = Order::query()->firstOrFail();

    expect($order->subtotal)->toBe('3999.98')
        ->and($order->total)->toBe('3999.98')
        ->and(round((float) $order->items()->sum('total'), 2))->toBe(3999.98);
});
