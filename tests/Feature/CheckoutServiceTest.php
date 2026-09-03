<?php

use App\Enums\CartStatus;
use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderCreated;
use App\Listeners\SendCustomerOrderEmail;
use App\Listeners\SendManagerOrderEmail;
use App\Listeners\SendOrderToBitrix;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CartManager;
use App\Services\CheckoutService;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeliveryMethodSetting::factory()->create([
        'code' => DeliveryMethod::TransportCompany,
        'title' => 'Транспортная компания',
        'description' => 'Delivery snapshot description',
        'base_price' => 700,
        'price_mode' => DeliveryPriceMode::Fixed,
        'is_active' => true,
    ]);
    PaymentMethodSetting::factory()->create([
        'code' => PaymentMethod::Sbp,
        'description' => 'Payment snapshot description',
        'title' => 'СБП',
        'is_active' => true,
    ]);
});

function checkoutRequest(Cart $cart, ?User $user = null): Request
{
    $request = Request::create('/checkout', 'POST', [], [CartManager::COOKIE_NAME => $cart->token]);
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

function validCheckoutData(array $overrides = []): array
{
    return [
        'customer_name' => 'Иван Петров',
        'customer_phone' => '+7 999 123-45-67',
        'customer_email' => null,
        'customer_city' => 'Москва',
        'customer_address' => 'Ленинградское шоссе, 1',
        'customer_comment' => 'Позвонить заранее',
        'delivery_method' => DeliveryMethod::TransportCompany->value,
        'payment_method' => PaymentMethod::Sbp->value,
        'agree_terms' => true,
        ...$overrides,
    ];
}

function cartWithSnapshotItem(float $price = 2500, int $quantity = 2): array
{
    $variant = ProductVariant::factory()->create([
        'title' => 'Комплект',
        'options' => ['material' => ['group' => 'Материал', 'value' => 'Оцинковка']],
        'price' => $price,
        'stock_quantity' => null,
    ]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(checkoutRequest($cart), $variant->getKey(), $quantity);

    return [$cart, $variant];
}

test('CheckoutService creates immutable order snapshots customer fields totals and completes cart', function () {
    Event::fake([OrderCreated::class]);
    $deliverySetting = DeliveryMethodSetting::query()->firstOrFail();
    $paymentSetting = PaymentMethodSetting::query()->firstOrFail();
    [$cart, $variant] = cartWithSnapshotItem();
    $cartItem = $cart->items()->firstOrFail();

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(),
    );
    $orderItem = $order->items->first();

    expect($order->status)->toBe(OrderStatus::New)
        ->and($order->payment_status)->toBe(PaymentStatus::Pending)
        ->and($order->payment_method)->toBe(PaymentMethod::Sbp)
        ->and($order->delivery_method)->toBe(DeliveryMethod::TransportCompany)
        ->and($order->delivery_method_title_snapshot)->toBe($deliverySetting->title)
        ->and($order->delivery_method_description_snapshot)->toBe('Delivery snapshot description')
        ->and($order->payment_method_title_snapshot)->toBe($paymentSetting->title)
        ->and($order->payment_method_description_snapshot)->toBe('Payment snapshot description')
        ->and($order->customer_name)->toBe('Иван Петров')
        ->and($order->customer_city)->toBe('Москва')
        ->and($order->customer_address)->toBe('Ленинградское шоссе, 1')
        ->and($order->delivery_address)->toBe('Ленинградское шоссе, 1')
        ->and($order->customer_comment)->toBe('Позвонить заранее')
        ->and($order->subtotal)->toBe('5000.00')
        ->and($order->delivery_price)->toBe('700.00')
        ->and($order->total)->toBe('5700.00')
        ->and($order->placed_at)->not->toBeNull()
        ->and($orderItem->title_snapshot)->toBe($cartItem->title_snapshot)
        ->and($orderItem->options_snapshot)->toBe($cartItem->options_snapshot)
        ->and($orderItem->price_snapshot)->toBe('2500.00')
        ->and($orderItem->total_snapshot)->toBe('5000.00')
        ->and($cart->refresh()->status)->toBe(CartStatus::Ordered);

    $variant->product->update(['title' => 'Изменённый товар']);
    $variant->update(['title' => 'Новый вариант', 'price' => 9900]);

    $deliverySetting->update(['title' => 'Changed delivery title']);
    $paymentSetting->update(['title' => 'Changed payment title']);

    expect($orderItem->refresh()->title_snapshot)->toBe($cartItem->title_snapshot)
        ->and($orderItem->price_snapshot)->toBe('2500.00')
        ->and($order->refresh()->delivery_method_title_snapshot)->not->toBe('Changed delivery title')
        ->and($order->payment_method_title_snapshot)->not->toBe('Changed payment title');

    Event::assertDispatched(OrderCreated::class, fn (OrderCreated $event): bool => $event->order->is($order));
});

test('CheckoutService creates a transport company order without customer address and preserves totals', function (): void {
    Event::fake([OrderCreated::class]);
    [$cart] = cartWithSnapshotItem();
    $checkoutData = validCheckoutData();
    unset($checkoutData['customer_address']);

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        $checkoutData,
    );

    expect($order->exists)->toBeTrue()
        ->and($order->delivery_method)->toBe(DeliveryMethod::TransportCompany)
        ->and($order->customer_address)->toBeNull()
        ->and($order->delivery_address)->toBeNull()
        ->and($order->subtotal)->toBe('5000.00')
        ->and($order->discount_total)->toBe('0.00')
        ->and($order->delivery_price)->toBe('700.00')
        ->and($order->total)->toBe('5700.00');
});

test('CheckoutService excludes technical variant management metadata from cart and order snapshots', function () {
    Event::fake([OrderCreated::class]);
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => null,
        'options' => [
            ...ProductVariant::technicalOptions(),
            'legacy' => ['group' => 'Legacy', 'value' => 'Публичное значение'],
        ],
    ]);
    $cart = Cart::factory()->create();
    $cartItem = app(CartManager::class)->addItem(checkoutRequest($cart), $variant->getKey());

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(),
    );
    $orderItem = $order->items->firstOrFail();

    expect($cartItem->options_snapshot)->not->toHaveKey('__dvashop')
        ->and($orderItem->options_snapshot)->toBe($cartItem->options_snapshot)
        ->and($orderItem->options_snapshot)->not->toHaveKey('__dvashop')
        ->and($orderItem->optionSummary())->toBe('Legacy: Публичное значение')
        ->and($orderItem->optionSummary())->not->toContain('__dvashop');
});

test('CheckoutService can create an order from valid snapshots after catalog deletion', function () {
    Event::fake([OrderCreated::class]);
    [$cart, $variant] = cartWithSnapshotItem(1800, 1);
    $variant->product->forceDeleteQuietly();

    $order = app(CheckoutService::class)->createOrderFromCart(checkoutRequest($cart), validCheckoutData());

    expect($order->items)->toHaveCount(1)
        ->and($order->items->first()->product_id)->toBeNull()
        ->and($order->items->first()->product_variant_id)->toBeNull()
        ->and($order->total)->toBe('2500.00');
});

test('CheckoutService ignores a guest supplied user id', function () {
    Event::fake([OrderCreated::class]);
    $otherUser = User::factory()->create();
    [$cart] = cartWithSnapshotItem();

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(['user_id' => $otherUser->getKey()]),
    );

    expect($order->user_id)->toBeNull();
});

test('CheckoutService uses only the authenticated user id', function () {
    Event::fake([OrderCreated::class]);
    $currentUser = User::factory()->create();
    $otherUser = User::factory()->create();
    [$cart] = cartWithSnapshotItem();

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart, $currentUser),
        validCheckoutData(['user_id' => $otherUser->getKey()]),
    );

    expect($order->user_id)->toBe($currentUser->getKey())
        ->and($order->user_id)->not->toBe($otherUser->getKey());
});

test('CheckoutService queues three independent after commit listeners without sending outbound calls inline', function () {
    Queue::fake();
    Mail::fake();
    [$cart] = cartWithSnapshotItem();

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(),
    );

    expect($order->exists)->toBeTrue();
    Mail::assertNothingSent();
    foreach ([SendCustomerOrderEmail::class, SendManagerOrderEmail::class, SendOrderToBitrix::class] as $listener) {
        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === $listener
            && $job->afterCommit === true);
    }
    Queue::assertPushedTimes(CallQueuedListener::class, 3);
});

test('CheckoutService dispatches OrderCreated only after the database transaction commits', function (): void {
    Queue::fake();
    $baselineTransactionLevel = DB::transactionLevel();
    $transactionLevels = [];
    Event::listen(OrderCreated::class, function () use (&$transactionLevels): void {
        $transactionLevels[] = DB::transactionLevel();
    });
    [$cart] = cartWithSnapshotItem();

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(),
    );

    expect($order->exists)->toBeTrue()
        ->and($transactionLevels)->toBe([$baselineTransactionLevel]);
});

test('CheckoutService creates an order without outbound calls when all channels are disabled', function (): void {
    Mail::fake();
    Http::fake();
    config()->set([
        'shop.orders.customer_email_enabled' => false,
        'shop.orders.manager_email_enabled' => false,
        'shop.orders.bitrix_enabled' => false,
    ]);
    [$cart] = cartWithSnapshotItem();

    $order = app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(['customer_email' => 'customer@example.test']),
    );

    expect($order->exists)->toBeTrue()
        ->and($order->customer_email_sent_at)->toBeNull()
        ->and($order->manager_email_sent_at)->toBeNull()
        ->and($order->bitrix_sent_at)->toBeNull();
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('CheckoutService rejects an empty cart', function () {
    $cart = Cart::factory()->create();

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(),
    ))->toThrow(ValidationException::class, 'пустую корзину');
});

test('CheckoutService validates payment and delivery methods', function (array $overrides) {
    [$cart] = cartWithSnapshotItem();

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData($overrides),
    ))->toThrow(ValidationException::class);
})->with([
    'unknown payment method' => [['payment_method' => 'crypto']],
    'unknown delivery method' => [['delivery_method' => 'teleport']],
]);

test('CheckoutService rejects inactive configured methods without creating an order', function (string $model): void {
    [$cart] = cartWithSnapshotItem();
    $model::query()->update(['is_active' => false]);

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(),
    ))->toThrow(ValidationException::class);

    expect(DB::table('orders')->count())->toBe(0)
        ->and($cart->refresh()->status)->toBe(CartStatus::Active);
})->with([
    'delivery' => [DeliveryMethodSetting::class],
    'payment' => [PaymentMethodSetting::class],
]);

test('CheckoutService creates only one order when the same cart is submitted twice', function (): void {
    Event::fake([OrderCreated::class]);
    [$cart] = cartWithSnapshotItem();
    $request = checkoutRequest($cart);

    $firstOrder = app(CheckoutService::class)->createOrderFromCart($request, validCheckoutData());

    expect(fn () => app(CheckoutService::class)->createOrderFromCart($request, validCheckoutData()))
        ->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(1)
        ->and(Order::query()->firstOrFail()->is($firstOrder))->toBeTrue();
});

test('CheckoutService rejects a non-positive item quantity', function () {
    Queue::fake();
    [$cart] = cartWithSnapshotItem();
    DB::table('cart_items')->where('cart_id', $cart->getKey())->update(['quantity' => 0]);

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData(),
    ))->toThrow(ValidationException::class, 'больше нуля');

    Queue::assertNothingPushed();
    expect($cart->refresh()->status)->toBe(CartStatus::Active)
        ->and(DB::table('orders')->count())->toBe(0);
});

test('CheckoutService requires customer identity and accepted terms while allowing nullable email', function (array $overrides) {
    [$cart] = cartWithSnapshotItem();

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        checkoutRequest($cart),
        validCheckoutData($overrides),
    ))->toThrow(ValidationException::class);
})->with([
    'name' => [['customer_name' => '']],
    'phone' => [['customer_phone' => '']],
    'terms' => [['agree_terms' => false]],
]);
