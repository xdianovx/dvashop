<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{0: Cart, 1: CartItem} */
function promoCart(float $price = 1000, int $quantity = 1): array
{
    $cart = Cart::factory()->create();
    $variant = ProductVariant::factory()->default()->create([
        'price' => $price,
        'stock_quantity' => 100,
    ]);
    $item = CartItem::factory()->forCart($cart)->forVariant($variant)->create([
        'price_snapshot' => $price,
        'quantity' => $quantity,
    ]);

    return [$cart, $item];
}

function cartPromo(array $attributes = []): PromoCode
{
    return PromoCode::factory()->create(['code' => 'SAVE10', ...$attributes]);
}

test('ordinary and JSON apply normalize code and return discount totals', function (): void {
    [$cart] = promoCart(1000, 2);
    cartPromo(['discount_value' => 10]);

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->from(route('cart.show'))
        ->post(route('cart.promo-code.store'), ['promo_code' => ' save10 '])
        ->assertRedirect(route('cart.show'));

    expect($cart->refresh()->promoCode->code)->toBe('SAVE10');

    $json = $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->postJson(route('cart.promo-code.store'), ['promo_code' => 'SaVe10'])
        ->assertOk()
        ->assertJsonPath('cart.subtotal', 2000)
        ->assertJsonPath('cart.discount_total', 200)
        ->assertJsonPath('cart.total', 1800)
        ->assertJsonPath('cart.promo_applied', true);

    expect($json->json('cart.items_count'))->toBe(2);
});

test('invalid unknown and unavailable promo applications are rejected', function (array $attributes, string $code): void {
    [$cart] = promoCart();

    if ($attributes !== []) {
        cartPromo($attributes);
    }

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->postJson(route('cart.promo-code.store'), ['promo_code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('promo_code');
})->with([
    'format' => [[], 'bad code'],
    'unknown' => [[], 'UNKNOWN'],
    'disabled' => [['is_active' => false], 'SAVE10'],
    'scheduled' => [['starts_at' => fn () => now()->addHour()], 'SAVE10'],
    'expired' => [['ends_at' => fn () => now()->subHour()], 'SAVE10'],
]);

test('exhausted promo is rejected', function (): void {
    [$cart] = promoCart();
    $promo = cartPromo(['usage_limit' => 1]);
    PromoCodeRedemption::factory()->for($promo)->create();

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->postJson(route('cart.promo-code.store'), ['promo_code' => 'SAVE10'])
        ->assertUnprocessable();
});

test('remove works with no JS and JSON', function (): void {
    [$cart] = promoCart();
    $promo = cartPromo();
    $cart->update(['promo_code_id' => $promo->getKey()]);

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->from(route('cart.show'))
        ->delete(route('cart.promo-code.destroy'))
        ->assertRedirect(route('cart.show'));
    expect($cart->refresh()->promo_code_id)->toBeNull();

    $cart->update(['promo_code_id' => $promo->getKey()]);
    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->deleteJson(route('cart.promo-code.destroy'))
        ->assertOk()
        ->assertJsonPath('cart.promo_applied', false)
        ->assertJsonPath('cart.discount_total', 0);
});

test('second valid promo replaces first while invalid second leaves first attached', function (): void {
    [$cart] = promoCart();
    $first = cartPromo(['code' => 'FIRST']);
    $second = cartPromo(['code' => 'SECOND', 'discount_value' => 20]);
    $cart->update(['promo_code_id' => $first->getKey()]);

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->postJson(route('cart.promo-code.store'), ['promo_code' => 'NOPE'])
        ->assertUnprocessable();
    expect($cart->refresh()->promo_code_id)->toBe($first->getKey());

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->postJson(route('cart.promo-code.store'), ['promo_code' => 'SECOND'])
        ->assertOk()
        ->assertJsonPath('cart.discount_total', 200);
    expect($cart->refresh()->promo_code_id)->toBe($second->getKey());
});

test('quantity and item mutations recalculate or detach promo and clear removes it', function (): void {
    [$cart, $item] = promoCart(600, 2);
    $promo = cartPromo(['minimum_eligible_subtotal' => 1000]);
    $cart->update(['promo_code_id' => $promo->getKey()]);

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->patchJson(route('cart.items.update', $item), ['quantity' => 1])
        ->assertOk()
        ->assertJsonPath('cart.promo_applied', false)
        ->assertJsonPath('cart.total', 600);
    expect($cart->refresh()->promo_code_id)->toBeNull();

    $cart->update(['promo_code_id' => $promo->getKey()]);
    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->deleteJson(route('cart.items.destroy', $item))
        ->assertOk()
        ->assertJsonPath('cart.items_count', 0)
        ->assertJsonPath('cart.promo_applied', false);

    [$anotherCart] = promoCart();
    $anotherCart->update(['promo_code_id' => $promo->getKey()]);
    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $anotherCart->token])
        ->deleteJson(route('cart.clear'))
        ->assertOk();
    expect($anotherCart->refresh()->promo_code_id)->toBeNull();
});

test('a cart cannot mutate an item owned by another cart', function (): void {
    [$cart] = promoCart();
    [, $foreignItem] = promoCart();

    $this->withCredentials()->withCookies([CartManager::COOKIE_NAME => $cart->token])
        ->deleteJson(route('cart.items.destroy', $foreignItem))
        ->assertNotFound();

    expect($foreignItem->fresh())->not->toBeNull();
});

test('cart without promo keeps fast totals path without promo graph queries', function (): void {
    [$cart] = promoCart(123.45, 2);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $totals = app(CartManager::class)->totals($cart);

    expect($totals)->toMatchArray([
        'items_count' => 2,
        'subtotal' => 246.9,
        'discount_total' => 0.0,
        'total' => 246.9,
    ])->and(implode("\n", $queries))
        ->not->toContain('promo_code_products')
        ->not->toContain('promo_code_product_categories')
        ->not->toContain('promo_code_part_types')
        ->not->toContain('promo_code_redemptions');
});
