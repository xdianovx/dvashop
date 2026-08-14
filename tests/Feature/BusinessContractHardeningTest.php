<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\FaqItem;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Models\StaticPageItem;
use App\Models\User;
use App\Services\CartManager;
use App\Services\CheckoutService;
use App\Services\Orders\OrderOperationsService;
use Database\Seeders\FaqSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function businessCheckoutRequest(Cart $cart): Request
{
    return Request::create('/checkout', 'POST', [], [CartManager::COOKIE_NAME => $cart->token]);
}

function businessCheckoutData(DeliveryMethodSetting $delivery, PaymentMethodSetting $payment): array
{
    return [
        'customer_name' => 'Тестовый покупатель',
        'customer_phone' => '+79990000000',
        'customer_city' => 'Москва',
        'customer_address' => 'Тестовый адрес, 1',
        'delivery_method' => $delivery->code->value,
        'payment_method' => $payment->code->value,
        'agree_terms' => true,
    ];
}

function businessCheckoutMethods(DeliveryPriceMode $mode = DeliveryPriceMode::Free, float $price = 0): array
{
    $delivery = DeliveryMethodSetting::factory()->create([
        'code' => DeliveryMethod::Pickup,
        'title' => 'Тестовая доставка',
        'base_price' => $price,
        'price_mode' => $mode,
        'is_active' => true,
    ]);
    $payment = PaymentMethodSetting::factory()->create([
        'code' => PaymentMethod::Card,
        'is_active' => true,
    ]);

    return [$delivery, $payment];
}

test('delivery price mode is explicit and on request order never presents the merchandise subtotal as final', function (): void {
    [$delivery, $payment] = businessCheckoutMethods(DeliveryPriceMode::OnRequest);
    $variant = ProductVariant::factory()->default()->create([
        'price' => 2500,
        'stock_quantity' => null,
    ]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(businessCheckoutRequest($cart), $variant->getKey(), 2);

    $order = app(CheckoutService::class)->createOrderFromCart(
        businessCheckoutRequest($cart),
        businessCheckoutData($delivery, $payment),
    );

    expect($delivery->priceLabel())->toBe('Стоимость уточнит менеджер')
        ->and($order->delivery_price_mode_snapshot)->toBe(DeliveryPriceMode::OnRequest)
        ->and($order->delivery_price)->toBe('0.00')
        ->and($order->subtotal)->toBe('5000.00')
        ->and($order->total)->toBe('5000.00')
        ->and($order->total_is_final)->toBeFalse()
        ->and($order->deliveryPriceText())->toBe('Доставка рассчитывается отдельно');

    $customerMail = view('emails.orders.customer-created', [
        'order' => $order->load('items'),
        'storeName' => 'AVTOPOROGI.ru',
    ])->render();

    expect($customerMail)
        ->toContain('Доставка рассчитывается отдельно')
        ->toContain('Сумма товаров (без доставки)')
        ->not->toContain('<strong>Итого</strong>');
});

test('finite in stock inventory is decremented at checkout and restored exactly once on cancellation', function (): void {
    [$delivery, $payment] = businessCheckoutMethods();
    $variant = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 3,
    ]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(businessCheckoutRequest($cart), $variant->getKey(), 2);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $order = app(CheckoutService::class)->createOrderFromCart(
        businessCheckoutRequest($cart),
        businessCheckoutData($delivery, $payment),
    );
    $item = $order->items->firstOrFail();

    expect($variant->refresh()->stock_quantity)->toBe(1)
        ->and($item->stock_was_decremented)->toBeTrue()
        ->and($item->stock_restored_at)->toBeNull()
        ->and(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from "product_variants"')
                && str_contains($sql, 'order by "id" asc')
        ))->toBeTrue();

    $actor = User::factory()->admin()->create();
    $canceled = app(OrderOperationsService::class)->update($actor, $order, ['status' => OrderStatus::Canceled]);
    $restoredAt = $item->refresh()->stock_restored_at;

    expect($canceled->status)->toBe(OrderStatus::Canceled)
        ->and($variant->refresh()->stock_quantity)->toBe(3)
        ->and($restoredAt)->not->toBeNull();

    app(OrderOperationsService::class)->update($actor, $canceled, ['status' => OrderStatus::Canceled]);

    expect($variant->refresh()->stock_quantity)->toBe(3)
        ->and($item->refresh()->stock_restored_at?->equalTo($restoredAt))->toBeTrue();
});

test('preorder and unlimited stock are not decremented', function (StockStatus $status, ?int $stock, int $quantity): void {
    [$delivery, $payment] = businessCheckoutMethods();
    $variant = ProductVariant::factory()->default()->create([
        'stock_status' => $status,
        'stock_quantity' => $stock,
    ]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(businessCheckoutRequest($cart), $variant->getKey(), $quantity);

    $order = app(CheckoutService::class)->createOrderFromCart(
        businessCheckoutRequest($cart),
        businessCheckoutData($delivery, $payment),
    );

    expect($variant->refresh()->stock_quantity)->toBe($stock)
        ->and($order->items->firstOrFail()->stock_was_decremented)->toBeFalse();
})->with([
    'unlimited in stock' => [StockStatus::InStock, null, 5],
    'finite preorder' => [StockStatus::PreOrder, 1, 5],
]);

test('checkout rechecks finite inventory under lock and rolls the order back when stock changed', function (): void {
    [$delivery, $payment] = businessCheckoutMethods();
    $variant = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 3,
    ]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(businessCheckoutRequest($cart), $variant->getKey(), 3);
    DB::table('product_variants')->where('id', $variant->getKey())->update(['stock_quantity' => 2]);

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        businessCheckoutRequest($cart),
        businessCheckoutData($delivery, $payment),
    ))->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0)
        ->and($variant->refresh()->stock_quantity)->toBe(2)
        ->and($cart->refresh()->status->value)->toBe('active');
});

test('system business content upgrade is dry run by default exact match only and idempotent', function (): void {
    $this->seed([FaqSeeder::class, StaticPageContentSeeder::class]);
    $oldPayment = 'Оплата картой на сайте, по счёту для юридических лиц или при получении — способ выбирается при оформлении заказа.';
    $oldHowTitle = 'Оплачиваете покупку при получении';

    DB::table('faq_items')->where('code', 'payment_methods')->update(['answer' => $oldPayment]);
    DB::table('faq_items')->where('code', 'delivery_process')->update(['answer' => 'Текст администратора']);
    DB::table('static_page_items')->where('code', 'how_step_pay')->update(['title' => $oldHowTitle]);

    Artisan::call('content:upgrade-business-contracts');
    $dryRunOutput = Artisan::output();

    expect($dryRunOutput)->toContain('faq.payment_methods.answer')
        ->toContain('old: '.$oldPayment)
        ->toContain('new: ')
        ->and(FaqItem::query()->where('code', 'payment_methods')->value('answer'))->toBe($oldPayment)
        ->and(FaqItem::query()->where('code', 'delivery_process')->value('answer'))->toBe('Текст администратора')
        ->and(StaticPageItem::query()->where('code', 'how_step_pay')->value('title'))->toBe($oldHowTitle);

    Artisan::call('content:upgrade-business-contracts', ['--apply' => true]);

    expect(FaqItem::query()->where('code', 'payment_methods')->value('answer'))->not->toBe($oldPayment)
        ->and(FaqItem::query()->where('code', 'delivery_process')->value('answer'))->toBe('Текст администратора')
        ->and(StaticPageItem::query()->where('code', 'how_step_pay')->value('title'))->not->toBe($oldHowTitle);

    Artisan::call('content:upgrade-business-contracts', ['--apply' => true]);
    expect(Artisan::output())->toContain('Точных старых системных значений для замены не найдено.');
});

test('seeded content describes only implemented payment delivery and registration behavior', function (): void {
    $this->seed([FaqSeeder::class, StaticPageContentSeeder::class]);

    expect(FaqItem::query()->where('code', 'payment_methods')->value('answer'))
        ->toContain('После подтверждения заказа')
        ->toContain('СБП по ссылке либо QR-коду')
        ->not->toContain('Оплата картой на сайте')
        ->and(FaqItem::query()->where('code', 'delivery_process')->value('answer'))
        ->toContain('менеджер подтвердит')
        ->not->toContain('рассчитываются при оформлении заказа по вашему адресу')
        ->and(FaqItem::query()->where('code', 'delivery_cost')->value('answer'))
        ->toContain('Самовывоз бесплатный')
        ->toContain('по запросу уточнит менеджер')
        ->and(FaqItem::query()->where('code', 'website_registration')->value('answer'))
        ->toContain('заказ оформляется без регистрации')
        ->and(StaticPageItem::query()->where('code', 'how_step_pay')->value('title'))
        ->toBe('Оплачиваете заказ доступным способом')
        ->and(StaticPageItem::query()->where('code', 'partners_about_payment')->value('text'))
        ->toContain('Условия оплаты согласуем')
        ->not->toContain('Проверяете, потом оплачиваете');
});
