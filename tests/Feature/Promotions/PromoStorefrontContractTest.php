<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cart renders accessible escaped promo controls and discount state', function (): void {
    DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Pickup, 'is_active' => true]);
    PaymentMethodSetting::factory()->create(['code' => PaymentMethod::Card, 'is_active' => true]);
    $promo = PromoCode::factory()->create([
        'code' => 'HTML10',
        'name' => '<script>alert(1)</script>',
        'discount_value' => 10,
    ]);
    $cart = Cart::factory()->create(['promo_code_id' => $promo->getKey()]);
    $variant = ProductVariant::factory()->default()->create(['price' => 1000, 'stock_quantity' => 10]);
    CartItem::factory()->forCart($cart)->forVariant($variant)->create(['price_snapshot' => 1000, 'quantity' => 1]);

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->get(route('cart.show'))
        ->assertOk()
        ->assertSee('Введите промокод')
        ->assertSee('Применить')
        ->assertSee('Удалить промокод')
        ->assertSee('HTML10')
        ->assertSee('Скидка')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);

});

test('checkout renders discounted merchandise base and keeps promo form outside order form', function (): void {
    DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Pickup, 'is_active' => true]);
    PaymentMethodSetting::factory()->create(['code' => PaymentMethod::Card, 'is_active' => true]);
    $promo = PromoCode::factory()->create(['code' => 'CHECKOUT-HTML', 'discount_value' => 10]);
    $cart = Cart::factory()->create(['promo_code_id' => $promo->getKey()]);
    $variant = ProductVariant::factory()->default()->create(['price' => 1000, 'stock_quantity' => 10]);
    CartItem::factory()->forCart($cart)->forVariant($variant)->create(['price_snapshot' => 1000, 'quantity' => 1]);

    $response = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->get(route('checkout.show'))
        ->assertOk()
        ->assertSee('data-promo-panel', false)
        ->assertSee('data-cart-discount-row', false);
    preg_match('/data-checkout-subtotal="([0-9.]+)"/', $response->getContent(), $matches);
    expect((float) ($matches[1] ?? -1))->toBe(900.0);

    $checkoutSource = file_get_contents(resource_path('views/checkout.blade.php'));
    expect(strpos($checkoutSource, '<x-promo-code-form'))->toBeLessThan(strpos($checkoutSource, '<form class="checkout-layout"'))
        ->and(substr_count($checkoutSource, '<form'))->toBe(1);
});

test('thanks page shows escaped promo snapshot and discount', function (): void {
    $order = Order::factory()->create([
        'promo_code_snapshot' => '<b>BAD</b>',
        'discount_total' => 100,
        'subtotal' => 1000,
        'total' => 900,
    ]);
    $token = 'thanks-promo-token';

    $this->withSession(['checkout_success.'.$order->getKey() => $token])
        ->get(route('checkout.success', ['order' => $order->number, 'token' => $token]))
        ->assertOk()
        ->assertSee('Скидка по промокоду')
        ->assertSee('&lt;b&gt;BAD&lt;/b&gt;', false)
        ->assertDontSee('<b>BAD</b>', false);
});

test('promo JavaScript keeps AJAX pending errors totals delivery and auto detach state synchronized', function (): void {
    $source = file_get_contents(resource_path('js/app.js'));

    expect($source)->toContain(
        "document.querySelectorAll('[data-promo-panel]')",
        'updatePromoPanels(cart)',
        'button.disabled = true',
        "feedback.classList.add('promo-code__feedback--error')",
        'element.dataset.checkoutSubtotal = String(cart.total)',
        "form.addEventListener('cart:totals-updated', renderCheckoutTotal)",
        'element.hidden = Number(cart.discount_total) <= 0',
    );
});
