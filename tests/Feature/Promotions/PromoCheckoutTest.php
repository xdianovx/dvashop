<?php

use App\Enums\CartStatus;
use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Services\CartManager;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeliveryMethodSetting::factory()->create([
        'code' => DeliveryMethod::TransportCompany,
        'base_price' => 700,
        'price_mode' => DeliveryPriceMode::Fixed,
        'is_active' => true,
    ]);
    PaymentMethodSetting::factory()->create([
        'code' => PaymentMethod::Sbp,
        'is_active' => true,
    ]);
});

function promoCheckoutRequest(Cart $cart): Request
{
    return Request::create('/checkout', 'POST', [], [CartManager::COOKIE_NAME => $cart->token]);
}

/** @return array<string, mixed> */
function promoCheckoutData(): array
{
    return [
        'customer_name' => 'Тест Покупатель',
        'customer_phone' => '+79990000000',
        'customer_email' => 'buyer@example.test',
        'customer_city' => 'Москва',
        'customer_address' => 'Улица, 1',
        'customer_comment' => null,
        'delivery_method' => DeliveryMethod::TransportCompany->value,
        'payment_method' => PaymentMethod::Sbp->value,
        'agree_terms' => true,
    ];
}

/** @return array{0: Cart, 1: ProductVariant} */
function promoCheckoutCart(float $price = 1000, int $quantity = 2, ?PromoCode $promo = null): array
{
    $variant = ProductVariant::factory()->default()->create([
        'price' => $price,
        'stock_quantity' => 20,
    ]);
    $cart = Cart::factory()->create(['promo_code_id' => $promo?->getKey()]);
    app(CartManager::class)->addItem(promoCheckoutRequest($cart), $variant->getKey(), $quantity);

    return [$cart->refresh(), $variant];
}

test('checkout without promo preserves gross totals and zero discount snapshots', function (): void {
    Event::fake([OrderCreated::class]);
    [$cart] = promoCheckoutCart();

    $order = app(CheckoutService::class)->createOrderFromCart(promoCheckoutRequest($cart), promoCheckoutData());

    expect($order->subtotal)->toBe('2000.00')
        ->and($order->discount_total)->toBe('0.00')
        ->and($order->total)->toBe('2700.00')
        ->and($order->promo_code_snapshot)->toBeNull()
        ->and($order->items->first()->discount_snapshot)->toBe('0.00')
        ->and($order->items->first()->final_total_snapshot)->toBe('2000.00')
        ->and(PromoCodeRedemption::query()->count())->toBe(0);
});

test('percentage promo checkout without address persists immutable order and exact line snapshots', function (): void {
    Event::fake([OrderCreated::class]);
    $promo = PromoCode::factory()->create([
        'code' => 'CHECKOUT10',
        'name' => 'Checkout ten',
        'discount_value' => 10,
    ]);
    [$cart] = promoCheckoutCart(333.33, 3, $promo);
    $checkoutData = promoCheckoutData();
    unset($checkoutData['customer_address']);

    $order = app(CheckoutService::class)->createOrderFromCart(promoCheckoutRequest($cart), $checkoutData);
    $lineDiscount = (float) $order->items->sum('discount_snapshot');

    expect($order->subtotal)->toBe('999.99')
        ->and($order->discount_total)->toBe('100.00')
        ->and($order->delivery_price)->toBe('700.00')
        ->and($order->total)->toBe('1599.99')
        ->and($order->customer_address)->toBeNull()
        ->and($order->delivery_address)->toBeNull()
        ->and($order->promo_code_snapshot)->toBe('CHECKOUT10')
        ->and($order->promo_name_snapshot)->toBe('Checkout ten')
        ->and($order->promo_discount_type_snapshot)->toBe('percentage')
        ->and($lineDiscount)->toBe(100.0)
        ->and($order->items->sum(fn ($item): float => $item->finalLineTotal()))->toBe(899.99)
        ->and($order->promoCodeRedemption)->not->toBeNull()
        ->and(PromoCodeRedemption::query()->count())->toBe(1)
        ->and(Cart::query()->active()->whereKeyNot($cart->getKey())->whereNull('promo_code_id')->exists())->toBeTrue();

    $promo->update(['code' => 'CHANGED', 'discount_value' => 99]);
    $promo->delete();

    expect($order->refresh()->promo_code_snapshot)->toBe('CHECKOUT10')
        ->and($order->discount_total)->toBe('100.00');
    Event::assertDispatchedTimes(OrderCreated::class, 1);
});

test('fixed and targeted promo discounts only eligible merchandise', function (): void {
    Event::fake([OrderCreated::class]);
    $promo = PromoCode::factory()->fixed(250)->targeted()->create(['code' => 'TARGET250']);
    [$cart, $eligibleVariant] = promoCheckoutCart(1000, 1, $promo);
    $otherVariant = ProductVariant::factory()->default()->create(['price' => 500, 'stock_quantity' => 10]);
    app(CartManager::class)->addItem(promoCheckoutRequest($cart), $otherVariant->getKey(), 1);
    $promo->products()->attach($eligibleVariant->product_id);

    $order = app(CheckoutService::class)->createOrderFromCart(promoCheckoutRequest($cart), promoCheckoutData());
    $lines = $order->items->keyBy('product_id');

    expect($order->subtotal)->toBe('1500.00')
        ->and($order->discount_total)->toBe('250.00')
        ->and($lines[$eligibleVariant->product_id]->discount_snapshot)->toBe('250.00')
        ->and($lines[$otherVariant->product_id]->discount_snapshot)->toBe('0.00');
});

test('on request delivery keeps non-final wording contract with discounted merchandise total', function (): void {
    Event::fake([OrderCreated::class]);
    DeliveryMethodSetting::query()->update([
        'price_mode' => DeliveryPriceMode::OnRequest,
        'base_price' => 0,
    ]);
    $promo = PromoCode::factory()->create(['discount_value' => 10]);
    [$cart] = promoCheckoutCart(1000, 1, $promo);

    $order = app(CheckoutService::class)->createOrderFromCart(promoCheckoutRequest($cart), promoCheckoutData());

    expect($order->total_is_final)->toBeFalse()
        ->and($order->subtotal)->toBe('1000.00')
        ->and($order->discount_total)->toBe('100.00')
        ->and($order->total)->toBe('900.00');
});

test('valid promo preserves finite stock reservation and creates one order and redemption', function (): void {
    Event::fake([OrderCreated::class]);
    $promo = PromoCode::factory()->create(['discount_value' => 10]);
    [$cart, $variant] = promoCheckoutCart(1000, 2, $promo);
    $stock = $variant->stock_quantity;

    $order = app(CheckoutService::class)->createOrderFromCart(
        promoCheckoutRequest($cart),
        promoCheckoutData(),
    );

    expect($variant->refresh()->stock_quantity)->toBe($stock - 2)
        ->and(Order::query()->count())->toBe(1)
        ->and(PromoCodeRedemption::query()->count())->toBe(1)
        ->and($order->discount_total)->toBe('200.00')
        ->and($order->items->first()->stock_was_decremented)->toBeTrue();
    Event::assertDispatchedTimes(OrderCreated::class, 1);
});

test('unlimited promo skips quota selects while preserving redemption history', function (int $existingCount): void {
    Event::fake([OrderCreated::class]);
    $promo = PromoCode::factory()->create([
        'discount_value' => 10,
        'usage_limit' => null,
    ]);
    $existingRedemptions = PromoCodeRedemption::factory()
        ->count($existingCount)
        ->for($promo)
        ->create();
    [$cart] = promoCheckoutCart(1000, 1, $promo);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $order = app(CheckoutService::class)->createOrderFromCart(
        promoCheckoutRequest($cart),
        promoCheckoutData(),
    );

    $redemptionQueries = collect($queries)->filter(
        fn (string $sql): bool => str_contains($sql, 'promo_code_redemptions')
    );
    $quotaSelects = $redemptionQueries->filter(
        fn (string $sql): bool => preg_match('/^select .* from ["`]?promo_code_redemptions/i', $sql) === 1
    );
    $redemptionInserts = $redemptionQueries->filter(
        fn (string $sql): bool => preg_match('/^insert into ["`]?promo_code_redemptions/i', $sql) === 1
    );

    expect($order->discount_total)->toBe('100.00')
        ->and($quotaSelects)->toHaveCount(0)
        ->and($redemptionInserts)->toHaveCount(1)
        ->and($redemptionQueries)->toHaveCount(1)
        ->and(PromoCodeRedemption::query()->whereKey($existingRedemptions->modelKeys())->count())->toBe($existingCount)
        ->and(PromoCodeRedemption::query()->where('promo_code_id', $promo->getKey())->count())->toBe($existingCount + 1)
        ->and(PromoCodeRedemption::query()->where('order_id', $order->getKey())->exists())->toBeTrue();
    Event::assertDispatchedTimes(OrderCreated::class, 1);
})->with([
    'one existing redemption' => [1],
    'fifty existing redemptions' => [50],
]);

test('invalid promo aborts atomically before stock reservation and never creates full price order', function (array $attributes): void {
    Event::fake([OrderCreated::class]);
    $promo = PromoCode::factory()->create($attributes);
    [$cart, $variant] = promoCheckoutCart(1000, 1, $promo);
    $stock = $variant->stock_quantity;

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        promoCheckoutRequest($cart),
        promoCheckoutData(),
    ))->toThrow(ValidationException::class, 'Промокод больше не действует');

    expect(Order::query()->count())->toBe(0)
        ->and(PromoCodeRedemption::query()->count())->toBe(0)
        ->and($variant->refresh()->stock_quantity)->toBe($stock)
        ->and($cart->refresh()->status)->toBe(CartStatus::Active);
    Event::assertNotDispatched(OrderCreated::class);
})->with([
    'disabled' => [['is_active' => false]],
    'scheduled' => [['starts_at' => fn () => now()->addMinute()]],
    'expired' => [['ends_at' => fn () => now()->subMinute()]],
]);

test('usage limit one rejects a second checkout after an active redemption', function (): void {
    Event::fake([OrderCreated::class]);
    $promo = PromoCode::factory()->create(['usage_limit' => 1]);
    PromoCodeRedemption::factory()->for($promo)->create();
    [$cart, $variant] = promoCheckoutCart(1000, 1, $promo);
    $stock = $variant->stock_quantity;
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        promoCheckoutRequest($cart),
        promoCheckoutData(),
    ))->toThrow(ValidationException::class);

    $quotaSelects = collect($queries)->filter(
        fn (string $sql): bool => preg_match('/^select .* from ["`]?promo_code_redemptions/i', $sql) === 1
    );

    expect(Order::query()->count())->toBe(1)
        ->and(PromoCodeRedemption::query()->count())->toBe(1)
        ->and($quotaSelects)->toHaveCount(1)
        ->and($variant->refresh()->stock_quantity)->toBe($stock)
        ->and($cart->refresh()->status)->toBe(CartStatus::Active);
    Event::assertNotDispatched(OrderCreated::class);
});
