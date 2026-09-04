<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Events\OrderCreated;
use App\Listeners\SendCustomerOrderEmail;
use App\Listeners\SendManagerOrderEmail;
use App\Listeners\SendOrderToBitrix;
use App\Mail\CustomerOrderCreatedMail;
use App\Mail\ManagerOrderCreatedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Services\Settings\ShopSettingsService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set([
        'shop.orders.customer_email_enabled' => true,
        'shop.orders.manager_email_enabled' => true,
        'shop.orders.bitrix_enabled' => true,
        'shop.orders_manager_email' => 'env-manager@example.test',
        'shop.bitrix.webhook_url' => 'https://bitrix.example.test/rest/1/secret-token',
        'shop.bitrix.source_id' => null,
        'shop.bitrix.responsible_id' => null,
        'shop.bitrix.order_method' => 'crm.lead.add',
        'shop.bitrix.order_product_rows_enabled' => false,
    ]);

    $settings = app(ShopSettingsService::class)->current();
    $settings->forceFill([
        'store_name' => 'МагазПороги',
        'order_notification_email' => 'db-manager@example.test',
    ])->save();

    Mail::fake();
});

function orderForNotification(?string $customerEmail = 'customer@example.test'): Order
{
    $order = Order::factory()->create([
        'customer_name' => 'Анна Смирнова',
        'customer_phone' => '+79990000000',
        'customer_email' => $customerEmail,
        'customer_city' => 'Москва',
        'customer_address' => 'Тестовая улица, 1',
        'customer_comment' => 'Позвонить заранее',
        'payment_method' => PaymentMethod::Card,
        'payment_method_title_snapshot' => 'Историческая оплата',
        'payment_method_description_snapshot' => 'Оплата после согласования',
        'delivery_method' => DeliveryMethod::Courier,
        'delivery_method_title_snapshot' => 'Историческая доставка',
        'delivery_method_description_snapshot' => 'Доставка до двери',
        'delivery_price_mode_snapshot' => DeliveryPriceMode::Fixed,
        'subtotal' => 3000,
        'delivery_price' => 500,
        'total' => 3500,
    ]);
    OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Порог тестовый',
        'sku_snapshot' => 'SNAP-SKU',
        'options_snapshot' => [
            ...ProductVariant::technicalOptions(),
            'material' => ['group' => 'Материал', 'value' => 'Оцинковка'],
        ],
        'quantity' => 2,
        'price_snapshot' => 1500,
        'total_snapshot' => 3000,
        'title' => 'Порог тестовый',
        'price' => 1500,
        'total' => 3000,
    ]);

    return $order->load('items');
}

function fakeOrderBitrixDelivery(int $leadId): void
{
    Http::preventStrayRequests();
    Http::fake([
        'bitrix.example.test/*/crm.lead.add.json' => Http::response(['result' => $leadId]),
        'bitrix.example.test/*/crm.lead.productrows.set.json' => Http::response(['result' => true]),
    ]);
}

function deliverOrderNotifications(Order $order): void
{
    $event = new OrderCreated($order);

    app(SendCustomerOrderEmail::class)->handle($event);
    app(SendManagerOrderEmail::class)->handle($event);
    app(SendOrderToBitrix::class)->handle($event);
}

test('order channels honor every independent feature flag combination', function (bool $customer, bool $manager, bool $bitrix): void {
    fakeOrderBitrixDelivery(912);
    config()->set([
        'shop.orders.customer_email_enabled' => $customer,
        'shop.orders.manager_email_enabled' => $manager,
        'shop.orders.bitrix_enabled' => $bitrix,
    ]);
    $order = orderForNotification();

    deliverOrderNotifications($order);

    $customer
        ? Mail::assertSent(CustomerOrderCreatedMail::class)
        : Mail::assertNotSent(CustomerOrderCreatedMail::class);
    $manager
        ? Mail::assertSent(ManagerOrderCreatedMail::class)
        : Mail::assertNotSent(ManagerOrderCreatedMail::class);
    Http::assertSentCount($bitrix ? 1 : 0);

    $order->refresh();
    expect($order->customer_email_sent_at !== null)->toBe($customer)
        ->and($order->manager_email_sent_at !== null)->toBe($manager)
        ->and($order->bitrix_sent_at !== null)->toBe($bitrix)
        ->and($order->bitrix_entity_id)->toBe($bitrix ? '912' : null);
})->with([
    'all on' => [true, true, true],
    'customer and manager' => [true, true, false],
    'customer and bitrix' => [true, false, true],
    'manager and bitrix' => [false, true, true],
    'customer only' => [true, false, false],
    'manager only' => [false, true, false],
    'bitrix only' => [false, false, true],
    'all off' => [false, false, false],
]);

test('manager recipient prefers database setting over environment fallback', function (): void {
    config()->set('shop.orders.bitrix_enabled', false);
    app(SendManagerOrderEmail::class)->handle(new OrderCreated(orderForNotification()));

    Mail::assertSent(ManagerOrderCreatedMail::class, fn (ManagerOrderCreatedMail $mail): bool => $mail->hasTo('db-manager@example.test'));
});

test('manager recipient falls back to environment and missing recipient logs a warning', function (): void {
    $settings = app(ShopSettingsService::class)->current();
    $settings->forceFill(['order_notification_email' => null])->save();
    $first = orderForNotification();

    app(SendManagerOrderEmail::class)->handle(new OrderCreated($first));
    Mail::assertSent(ManagerOrderCreatedMail::class, fn (ManagerOrderCreatedMail $mail): bool => $mail->hasTo('env-manager@example.test'));

    Mail::fake();
    Log::spy();
    config()->set('shop.orders_manager_email', null);
    $second = orderForNotification();
    app(SendManagerOrderEmail::class)->handle(new OrderCreated($second));

    Mail::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

test('order emails render stored snapshots and configured store name without internal metadata', function (): void {
    config()->set('shop.orders.bitrix_enabled', false);
    $order = orderForNotification();

    app(SendCustomerOrderEmail::class)->handle(new OrderCreated($order));
    app(SendManagerOrderEmail::class)->handle(new OrderCreated($order));

    Mail::assertSent(CustomerOrderCreatedMail::class, function (CustomerOrderCreatedMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'МагазПороги')
            && ! str_contains($html, '2POROGA')
            && str_contains($html, 'Историческая оплата')
            && str_contains($html, 'Историческая доставка')
            && str_contains($html, 'Материал: Оцинковка')
            && ! str_contains($html, '__dvashop');
    });
    Mail::assertSent(ManagerOrderCreatedMail::class, fn (ManagerOrderCreatedMail $mail): bool => str_contains($mail->render(), 'МагазПороги'));
});

test('promo order emails and Bitrix distinguish gross discount delivery and final snapshots', function (): void {
    fakeOrderBitrixDelivery(913);
    $order = orderForNotification();
    $order->forceFill([
        'promo_code_snapshot' => 'MAIL400',
        'promo_name_snapshot' => 'Почтовая скидка',
        'discount_total' => 400,
        'delivery_price' => 250,
        'total' => 2850,
    ])->save();
    $order->items()->update([
        'discount_snapshot' => 400,
        'final_total_snapshot' => 2600,
    ]);
    $order->load('items');

    app(SendCustomerOrderEmail::class)->handle(new OrderCreated($order));
    app(SendManagerOrderEmail::class)->handle(new OrderCreated($order));
    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    foreach ([CustomerOrderCreatedMail::class, ManagerOrderCreatedMail::class] as $mailClass) {
        Mail::assertSent($mailClass, function ($mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Товары')
                && str_contains($html, '3 000,00')
                && str_contains($html, 'Промокод')
                && str_contains($html, 'MAIL400')
                && str_contains($html, 'Скидка')
                && str_contains($html, '400,00')
                && str_contains($html, 'Стоимость доставки')
                && str_contains($html, '250 ₽')
                && str_contains($html, 'Итого')
                && str_contains($html, '2 850,00');
        });
    }

    $description = '';
    Http::assertSent(function (Request $request) use (&$description): bool {
        if (! str_ends_with($request->url(), '/crm.lead.add.json')) {
            return false;
        }

        $description = (string) data_get($request->data(), 'fields.SOURCE_DESCRIPTION');

        return true;
    });
    expect($description)->toContain(
        'Номер заказа: '.$order->number,
        'Оформлен: '.$order->placed_at->format('d.m.Y H:i'),
        'Клиент: Анна Смирнова',
        'Телефон: +79990000000',
        'Email: customer@example.test',
        'Город: Москва',
        'Адрес: Тестовая улица, 1',
        'Комментарий клиента: Позвонить заранее',
        '1. Порог тестовый',
        'SKU: SNAP-SKU',
        'Опции: Материал: Оцинковка',
        'Количество: 2',
        'Цена: 1 500,00 ₽',
        'Товары: 3 000,00 ₽',
        'Промокод: MAIL400',
        'Скидка: 400,00 ₽',
        'Сумма до скидки: 3 000,00 ₽',
        'Сумма: 2 600,00 ₽',
        'Стоимость доставки: 250 ₽',
        'Доставка: Историческая доставка',
        'Описание доставки: Доставка до двери',
        'Код доставки: courier',
        'Оплата: Историческая оплата',
        'Описание оплаты: Оплата после согласования',
        'Итого: 2 850,00 ₽',
    );
    expect(strip_tags($description))->toBe($description);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => array_key_exists('COMMENTS', $request->data()['fields'] ?? []));
});

test('order product rows default is false when the environment variable is absent', function (): void {
    $process = new Process([
        PHP_BINARY, '-r',
        'require "vendor/autoload.php"; $settings = require "config/shop.php"; echo json_encode($settings["bitrix"]["order_product_rows_enabled"]);',
    ], base_path(), ['BITRIX_ORDER_PRODUCT_ROWS_ENABLED' => false]);
    $process->mustRun();

    expect($process->getOutput())->toBe('false');
});

test('disabled product rows send only one lead and full success stays idempotent after reenabling', function (): void {
    fakeOrderBitrixDelivery(912);
    $event = new OrderCreated(orderForNotification());

    app(SendOrderToBitrix::class)->handle($event);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/crm.lead.add.json')
        && isset($request['fields']['SOURCE_DESCRIPTION'])
        && ! isset($request['fields']['COMMENTS']));
    expect($event->order->fresh()->bitrix_entity_id)->toBe('912')
        ->and($event->order->fresh()->bitrix_sent_at)->not->toBeNull();

    Http::fake();
    app(SendOrderToBitrix::class)->handle($event);
    config()->set('shop.bitrix.order_product_rows_enabled', true);
    app(SendOrderToBitrix::class)->handle($event);
    Http::assertNothingSent();
});

test('disabled product rows finish an existing partial entity without any HTTP requests', function (): void {
    Http::fake();
    $order = orderForNotification();
    $order->update(['bitrix_entity_id' => '123', 'bitrix_sent_at' => null]);

    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    Http::assertNothingSent();
    expect($order->fresh()->bitrix_entity_id)->toBe('123')
        ->and($order->fresh()->bitrix_sent_at)->not->toBeNull();
});

test('local failure after saving lead id does not create another lead with product rows disabled', function (): void {
    fakeOrderBitrixDelivery(912);
    $order = orderForNotification();
    $failFinalSave = true;
    Order::saving(function (Order $saving) use ($order, &$failFinalSave): void {
        if ($saving->getKey() === $order->getKey() && $saving->isDirty('bitrix_sent_at') && $failFinalSave) {
            throw new RuntimeException('Simulated local final save failure');
        }
    });
    $event = new OrderCreated($order);

    expect(fn () => app(SendOrderToBitrix::class)->handle($event))->toThrow(RuntimeException::class);
    expect($order->fresh()->bitrix_entity_id)->toBe('912')
        ->and($order->fresh()->bitrix_sent_at)->toBeNull();
    Http::assertSentCount(1);

    $failFinalSave = false;
    Http::fake();
    app(SendOrderToBitrix::class)->handle($event);

    Http::assertNothingSent();
    expect($order->fresh()->bitrix_entity_id)->toBe('912')
        ->and($order->fresh()->bitrix_sent_at)->not->toBeNull();
});

test('order source description omits absent optional snapshots and never uses COMMENTS', function (): void {
    fakeOrderBitrixDelivery(912);
    $order = orderForNotification(null);
    $order->update([
        'customer_city' => null, 'customer_address' => null, 'customer_comment' => null,
        'delivery_method_description_snapshot' => null, 'payment_method_description_snapshot' => null,
    ]);
    $order->items()->update(['sku_snapshot' => null, 'options_snapshot' => null]);

    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    $fields = Http::recorded()[0][0]->data()['fields'];
    expect($fields)->not->toHaveKey('COMMENTS')
        ->and($fields['SOURCE_DESCRIPTION'])->not->toContain(
            'Email:', 'Город:', 'Адрес:', 'Комментарий клиента:', 'SKU:', 'Опции:',
            'Промокод:', 'Скидка:', 'Описание доставки:', 'Описание оплаты:',
        );
});

test('order emails without promo do not render an empty promo row', function (): void {
    config()->set('shop.orders.bitrix_enabled', false);
    $order = orderForNotification();

    app(SendCustomerOrderEmail::class)->handle(new OrderCreated($order));
    app(SendManagerOrderEmail::class)->handle(new OrderCreated($order));

    Mail::assertSent(CustomerOrderCreatedMail::class, fn (CustomerOrderCreatedMail $mail): bool => ! str_contains($mail->render(), '<td>Промокод</td>'));
    Mail::assertSent(ManagerOrderCreatedMail::class, fn (ManagerOrderCreatedMail $mail): bool => ! str_contains($mail->render(), 'Промокод'));
});

test('store name has safe fallback when database value is empty', function (): void {
    $settings = app(ShopSettingsService::class)->current();
    $settings->forceFill(['store_name' => ''])->save();
    config()->set(['shop.orders.manager_email_enabled' => false, 'shop.orders.bitrix_enabled' => false]);

    app(SendCustomerOrderEmail::class)->handle(new OrderCreated(orderForNotification()));

    Mail::assertSent(CustomerOrderCreatedMail::class, fn (CustomerOrderCreatedMail $mail): bool => str_contains($mail->render(), 'AVTOPOROGI.ru'));
});

test('order Bitrix payload contains only order and item snapshots', function (): void {
    fakeOrderBitrixDelivery(912);
    config()->set([
        'shop.bitrix.source_id' => 25,
        'shop.bitrix.responsible_id' => 130633,
    ]);
    $order = orderForNotification();

    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    Http::assertSent(function (Request $request) use ($order): bool {
        $fields = $request->data()['fields'] ?? [];
        $description = (string) ($fields['SOURCE_DESCRIPTION'] ?? '');

        return array_keys($fields) === ['TITLE', 'NAME', 'PHONE', 'EMAIL', 'SOURCE_DESCRIPTION', 'OPPORTUNITY', 'CURRENCY_ID', 'IS_MANUAL_OPPORTUNITY', 'SOURCE_ID', 'ASSIGNED_BY_ID']
            && $fields['OPPORTUNITY'] === '3500.00'
            && $fields['CURRENCY_ID'] === 'RUB'
            && $fields['IS_MANUAL_OPPORTUNITY'] === 'Y'
            && $fields['SOURCE_ID'] === '25'
            && $fields['ASSIGNED_BY_ID'] === '130633'
            && $fields['TITLE'] === 'Заказ '.$order->number
            && $fields['NAME'] === 'Анна Смирнова'
            && str_contains($description, $order->number)
            && str_contains($description, 'Порог тестовый')
            && str_contains($description, 'SNAP-SKU')
            && str_contains($description, 'Материал: Оцинковка')
            && str_contains($description, 'Количество: 2')
            && str_contains($description, 'Историческая доставка')
            && str_contains($description, '500,00 ₽')
            && str_contains($description, 'Историческая оплата')
            && str_contains($description, '3 500,00 ₽')
            && ! str_contains($description, '__dvashop')
            && ! str_contains(json_encode($fields, JSON_THROW_ON_ERROR), 'UF_');
    });
});

test('order Bitrix payload omits empty source and responsible fields', function (): void {
    fakeOrderBitrixDelivery(912);
    config()->set([
        'shop.bitrix.source_id' => null,
        'shop.bitrix.responsible_id' => '   ',
    ]);

    app(SendOrderToBitrix::class)->handle(new OrderCreated(orderForNotification()));

    Http::assertSent(function (Request $request): bool {
        $fields = $request->data()['fields'] ?? [];

        return str_ends_with($request->url(), '/crm.lead.add.json')
            && ! array_key_exists('SOURCE_ID', $fields)
            && ! array_key_exists('ASSIGNED_BY_ID', $fields);
    });
});

test('on request delivery is explicit in order emails and Bitrix and subtotal is not called final', function (): void {
    fakeOrderBitrixDelivery(912);
    $order = orderForNotification()->forceFill([
        'delivery_price_mode_snapshot' => DeliveryPriceMode::OnRequest,
        'delivery_price' => 0,
        'total' => 3000,
        'total_is_final' => false,
    ]);
    $order->save();

    app(SendCustomerOrderEmail::class)->handle(new OrderCreated($order));
    app(SendManagerOrderEmail::class)->handle(new OrderCreated($order));
    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    Mail::assertSent(CustomerOrderCreatedMail::class, fn (CustomerOrderCreatedMail $mail): bool => str_contains($mail->render(), 'Доставка рассчитывается отдельно')
        && str_contains($mail->render(), 'Сумма товаров (без доставки)')
        && ! str_contains($mail->render(), '<strong>Итого</strong>'));
    Mail::assertSent(ManagerOrderCreatedMail::class, fn (ManagerOrderCreatedMail $mail): bool => str_contains($mail->render(), 'Доставка рассчитывается отдельно')
        && str_contains($mail->render(), 'Сумма товаров (без доставки)'));
    Http::assertSent(function (Request $request): bool {
        $description = (string) data_get($request->data(), 'fields.SOURCE_DESCRIPTION');

        return str_contains($description, 'Стоимость доставки: Доставка рассчитывается отдельно')
            && str_contains($description, 'Сумма товаров (без доставки): 3 000,00 ₽')
            && ! str_contains($description, 'Итого: 3 000,00 ₽');
    });
});

test('customer SMTP failure does not prevent manager mail or Bitrix', function (): void {
    fakeOrderBitrixDelivery(912);
    $order = orderForNotification();
    $event = new OrderCreated($order);
    Mail::shouldReceive('to')->with('customer@example.test')->once()->andThrow(new RuntimeException('Customer SMTP unavailable'));

    expect(fn () => app(SendCustomerOrderEmail::class)->handle($event))->toThrow(RuntimeException::class);

    Mail::clearResolvedInstance('mail.manager');
    app()->forgetInstance('mail.manager');
    Mail::fake();
    app(SendManagerOrderEmail::class)->handle($event);
    app(SendOrderToBitrix::class)->handle($event);

    Mail::assertSent(ManagerOrderCreatedMail::class);
    Http::assertSentCount(1);
    expect($order->refresh()->customer_email_sent_at)->toBeNull()
        ->and($order->manager_email_sent_at)->not->toBeNull()
        ->and($order->bitrix_sent_at)->not->toBeNull();
});

test('manager SMTP failure does not prevent customer mail or Bitrix', function (): void {
    fakeOrderBitrixDelivery(912);
    $order = orderForNotification();
    $event = new OrderCreated($order);
    Mail::shouldReceive('to')->with('db-manager@example.test')->once()->andThrow(new RuntimeException('Manager SMTP unavailable'));

    expect(fn () => app(SendManagerOrderEmail::class)->handle($event))->toThrow(RuntimeException::class);

    Mail::clearResolvedInstance('mail.manager');
    app()->forgetInstance('mail.manager');
    Mail::fake();
    app(SendCustomerOrderEmail::class)->handle($event);
    app(SendOrderToBitrix::class)->handle($event);

    Mail::assertSent(CustomerOrderCreatedMail::class);
    Http::assertSentCount(1);
    expect($order->refresh()->manager_email_sent_at)->toBeNull()
        ->and($order->customer_email_sent_at)->not->toBeNull()
        ->and($order->bitrix_sent_at)->not->toBeNull();
});

test('Bitrix failure keeps order and does not prevent either email', function (): void {
    Http::fake(['bitrix.example.test/*' => Http::response(['error' => 'temporary'], 500)]);
    $order = orderForNotification();
    $event = new OrderCreated($order);

    expect(fn () => app(SendOrderToBitrix::class)->handle($event))->toThrow(RequestException::class);
    app(SendCustomerOrderEmail::class)->handle($event);
    app(SendManagerOrderEmail::class)->handle($event);

    expect(Order::query()->whereKey($order->getKey())->exists())->toBeTrue()
        ->and($order->refresh()->bitrix_sent_at)->toBeNull()
        ->and($order->customer_email_sent_at)->not->toBeNull()
        ->and($order->manager_email_sent_at)->not->toBeNull();
    Mail::assertSent(CustomerOrderCreatedMail::class);
    Mail::assertSent(ManagerOrderCreatedMail::class);
});

test('confirmed order channels are idempotent across retries', function (): void {
    fakeOrderBitrixDelivery(912);
    $order = orderForNotification();

    deliverOrderNotifications($order);
    deliverOrderNotifications($order->refresh());

    Mail::assertSentCount(2);
    Http::assertSentCount(1);
});

test('OrderCreated has exactly three independent after commit queued listeners', function (): void {
    $listeners = app(Dispatcher::class)->getRawListeners()[OrderCreated::class] ?? [];

    expect($listeners)->toHaveCount(3)
        ->and($listeners)->toEqualCanonicalizing([
            SendCustomerOrderEmail::class.'@handle',
            SendManagerOrderEmail::class.'@handle',
            SendOrderToBitrix::class.'@handle',
        ])
        ->and(app(SendCustomerOrderEmail::class))->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and(app(SendManagerOrderEmail::class))->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and(app(SendOrderToBitrix::class))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('each order channel reports its own final queued failure', function (string $listener, string $message): void {
    Log::spy();
    $order = orderForNotification();

    app($listener)->failed(new OrderCreated($order), new RuntimeException('channel unavailable'));

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $loggedMessage, array $context): bool => $loggedMessage === $message
            && $context['order_id'] === $order->getKey()
            && $context['order_number'] === $order->number
            && $context['exception'] === 'channel unavailable',
    );
})->with([
    'customer' => [SendCustomerOrderEmail::class, 'Queued customer order email failed.'],
    'manager' => [SendManagerOrderEmail::class, 'Queued manager order email failed.'],
    'Bitrix' => [SendOrderToBitrix::class, 'Queued order Bitrix delivery failed.'],
]);
