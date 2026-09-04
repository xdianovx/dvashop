<?php

declare(strict_types=1);

use App\Enums\DeliveryPriceMode;
use App\Events\OrderCreated;
use App\Listeners\SendOrderToBitrix;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Integrations\BitrixWebhookClient;
use App\Services\Promotions\PromoCodePricingService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set([
        'shop.orders.bitrix_enabled' => true,
        'shop.orders.customer_email_enabled' => false,
        'shop.orders.manager_email_enabled' => false,
        'shop.bitrix.webhook_url' => 'https://example.test/rest/7/token/',
        'shop.bitrix.order_method' => 'crm.lead.add',
        'shop.bitrix.source_id' => 25,
        'shop.bitrix.responsible_id' => 130633,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'example.test/*/crm.lead.add.json' => Http::response(['result' => 123]),
        'example.test/*/crm.lead.productrows.set.json' => Http::response(['result' => true]),
    ]);
});

function bitrixRowsOrder(): Order
{
    $order = Order::factory()->create([
        'customer_name' => 'Тестовый клиент',
        'customer_phone' => '+79990000000',
        'customer_email' => 'customer@example.test',
        'promo_code_snapshot' => 'SAVE401',
        'subtotal' => '3300.00',
        'discount_total' => '401.00',
        'delivery_price_mode_snapshot' => DeliveryPriceMode::Fixed,
        'delivery_method_title_snapshot' => 'Доставка из snapshot',
        'payment_method_title_snapshot' => 'Оплата из snapshot',
        'delivery_price' => '250.00',
        'total' => '3149.00',
    ]);
    OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Порог левый',
        'sku_snapshot' => 'SNAP-LEFT',
        'options_snapshot' => ['material' => ['group' => 'Материал', 'value' => 'Оцинковка']],
        'quantity' => 2,
        'price_snapshot' => '1500.00',
        'total_snapshot' => '3000.00',
        'discount_snapshot' => '400.00',
        'final_total_snapshot' => '2600.00',
    ]);
    OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Порог правый',
        'sku_snapshot' => 'SNAP-RIGHT',
        'options_snapshot' => ['side' => ['group' => 'Сторона', 'value' => 'Правая']],
        'quantity' => 3,
        'price_snapshot' => '100.00',
        'total_snapshot' => '300.00',
        'discount_snapshot' => '1.00',
        'final_total_snapshot' => '299.00',
    ]);

    return $order;
}

test('order lead amount and form encoded product rows use immutable discounted snapshots', function (): void {
    $order = bitrixRowsOrder();
    $originalOrder = $order->refresh()->getAttributes();
    $originalItems = $order->items()->get()->map->getAttributes()->all();
    foreach ($order->items as $item) {
        $item->product->update(['title' => 'LIVE PRODUCT CHANGED']);
        $item->variant->update(['sku' => 'LIVE-SKU-CHANGED-'.$item->getKey(), 'price' => 9999]);
    }

    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    Http::assertSentCount(2);
    $requests = Http::recorded()->map(fn (array $record): Request => $record[0]);
    expect($requests[0]->url())->toBe('https://example.test/rest/7/token/crm.lead.add.json')
        ->and($requests[1]->url())->toBe('https://example.test/rest/7/token/crm.lead.productrows.set.json');
    $fields = $requests[0]->data()['fields'];
    expect($fields)->toMatchArray([
        'TITLE' => 'Заказ '.$order->number,
        'NAME' => 'Тестовый клиент',
        'PHONE' => [['VALUE' => '+79990000000', 'VALUE_TYPE' => 'WORK']],
        'EMAIL' => [['VALUE' => 'customer@example.test', 'VALUE_TYPE' => 'WORK']],
        'SOURCE_ID' => '25',
        'ASSIGNED_BY_ID' => '130633',
        'OPPORTUNITY' => '3149.00',
        'CURRENCY_ID' => 'RUB',
        'IS_MANUAL_OPPORTUNITY' => 'Y',
    ])->and($fields['COMMENTS'])->toContain(
        'Порог левый', 'SNAP-LEFT', 'Материал: Оцинковка',
        'Порог правый', 'SNAP-RIGHT', 'Сторона: Правая',
        'Количество: 2', 'Количество: 3', 'Цена: 1 500,00 ₽',
        'Товары: 3 300,00 ₽', 'Промокод: SAVE401', 'Скидка: 401,00 ₽',
        'Сумма: 2 600,00 ₽', 'Сумма: 299,00 ₽',
        'Стоимость доставки: 250 ₽', 'Доставка из snapshot', 'Оплата из snapshot',
        'Итого: 3 149,00 ₽',
    )->not->toContain('LIVE PRODUCT CHANGED', 'LIVE-SKU-CHANGED');

    expect($requests[1]->hasHeader('Content-Type', 'application/x-www-form-urlencoded'))->toBeTrue();
    parse_str($requests[1]->body(), $body);
    expect($body)->toBe([
        'id' => '123',
        'rows' => [
            ['PRODUCT_NAME' => 'Порог левый — SKU: SNAP-LEFT', 'PRICE' => '1300.00', 'QUANTITY' => '2'],
            ['PRODUCT_NAME' => 'Порог правый — SKU: SNAP-RIGHT', 'PRICE' => '99.66', 'QUANTITY' => '1'],
            ['PRODUCT_NAME' => 'Порог правый — SKU: SNAP-RIGHT', 'PRICE' => '99.67', 'QUANTITY' => '2'],
        ],
    ])->and($requests[1]->body())->toContain('rows%5B0%5D%5BPRICE%5D=1300.00')
        ->and(array_sum(array_map(fn (array $row): int => app(PromoCodePricingService::class)->moneyToCents($row['PRICE']) * (int) $row['QUANTITY'], $body['rows'])))->toBe(289900);

    $order->refresh();
    expect($order->bitrix_entity_id)->toBe('123')
        ->and($order->bitrix_sent_at)->not->toBeNull()
        ->and(collect($order->getAttributes())->except(['bitrix_entity_id', 'bitrix_sent_at', 'updated_at'])->all())
        ->toBe(collect($originalOrder)->except(['bitrix_entity_id', 'bitrix_sent_at', 'updated_at'])->all())
        ->and($order->items()->get()->map->getAttributes()->all())->toBe($originalItems);
});

test('on request delivery keeps the saved non final amount without imaginary delivery', function (): void {
    $order = bitrixRowsOrder();
    $order->update([
        'delivery_price_mode_snapshot' => DeliveryPriceMode::OnRequest,
        'delivery_price' => 0,
        'total' => '2899.00',
        'total_is_final' => false,
    ]);

    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'fields.OPPORTUNITY') === '2899.00'
        && str_contains((string) data_get($request->data(), 'fields.COMMENTS'), 'Сумма товаров (без доставки): 2 899,00 ₽'));
    expect($order->refresh()->total_is_final)->toBeFalse();
});

test('product rows failure persists lead id and retry only sets rows', function (array $response, int $status, string $exception): void {
    $order = bitrixRowsOrder();
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        'example.test/*/crm.lead.add.json' => Http::response(['result' => 123]),
        'example.test/*/crm.lead.productrows.set.json' => Http::sequence()
            ->push($response, $status)->push(['result' => true]),
    ]);
    $event = new OrderCreated($order);

    expect(fn () => app(SendOrderToBitrix::class)->handle($event))->toThrow($exception);
    expect($order->fresh()->bitrix_entity_id)->toBe('123')
        ->and($order->fresh()->bitrix_sent_at)->toBeNull();
    Http::assertSentCount(2);

    app(SendOrderToBitrix::class)->handle($event);

    Http::assertSentCount(3);
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/crm.lead.add.json')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/crm.lead.productrows.set.json') && $request['id'] === '123'))->toHaveCount(2)
        ->and($order->fresh()->bitrix_sent_at)->not->toBeNull();
})->with([
    'HTTP failure' => [['error' => 'temporary'], 500, RequestException::class],
    'API error with HTTP 200' => [['error' => 'ERROR', 'error_description' => 'temporary'], 200, InvalidArgumentException::class],
    'false result' => [['result' => false], 200, InvalidArgumentException::class],
    'missing result' => [[], 200, InvalidArgumentException::class],
]);

test('fully delivered order makes no requests on a stale event retry', function (): void {
    $event = new OrderCreated(bitrixRowsOrder());
    app(SendOrderToBitrix::class)->handle($event);
    Http::assertSentCount(2);
    Http::fake();

    app(SendOrderToBitrix::class)->handle($event);

    Http::assertNothingSent();
});

test('lead add failure leaves the order unsent and retry can create its lead', function (): void {
    $order = bitrixRowsOrder();
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        'example.test/*/crm.lead.add.json' => Http::sequence()->push(['error' => 'temporary'], 500)->push(['result' => 123]),
        'example.test/*/crm.lead.productrows.set.json' => Http::response(['result' => true]),
    ]);

    expect(fn () => app(SendOrderToBitrix::class)->handle(new OrderCreated($order)))->toThrow(RequestException::class);
    expect($order->fresh()->bitrix_entity_id)->toBeNull()
        ->and($order->fresh()->bitrix_sent_at)->toBeNull();
    Http::assertSentCount(1);
    $this->assertModelExists($order);

    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));
    Http::assertSentCount(3);
    expect($order->fresh()->bitrix_sent_at)->not->toBeNull();
});

test('overlapping delivery cannot create another lead before the first id is saved', function (): void {
    $event = new OrderCreated(bitrixRowsOrder());
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        'example.test/*/crm.lead.add.json' => function () use ($event) {
            expect(fn () => app(SendOrderToBitrix::class)->handle($event))->toThrow(LockTimeoutException::class);

            return Http::response(['result' => 123]);
        },
        'example.test/*/crm.lead.productrows.set.json' => function () use ($event) {
            expect($event->order->fresh()->bitrix_entity_id)->toBe('123')
                ->and($event->order->fresh()->bitrix_sent_at)->toBeNull();

            return Http::response(['result' => true]);
        },
    ]);

    app(SendOrderToBitrix::class)->handle($event);

    Http::assertSentCount(2);
    expect($event->order->fresh()->bitrix_sent_at)->not->toBeNull();
});

test('empty item collection skips product rows and remains idempotent', function (): void {
    $order = Order::factory()->create(['subtotal' => 0, 'total' => 0]);
    $event = new OrderCreated($order);

    app(SendOrderToBitrix::class)->handle($event);
    app(SendOrderToBitrix::class)->handle($event);

    Http::assertSentCount(1);
    expect($order->fresh()->bitrix_sent_at)->not->toBeNull();
});

test('cent allocation preserves every cent and quantity including zero and single unit totals', function (int $quantity, string $total, array $expected): void {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Товар', 'sku_snapshot' => null,
        'quantity' => $quantity, 'final_total_snapshot' => $total,
    ]);

    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));

    $rows = Http::recorded()[1][0]->data()['rows'];
    expect(array_map(fn (array $row): array => [$row['PRICE'], (int) $row['QUANTITY']], $rows))->toBe($expected)
        ->and(array_sum(array_column($rows, 'QUANTITY')))->toBe($quantity)
        ->and(array_sum(array_map(fn (array $row): int => app(PromoCodePricingService::class)->moneyToCents($row['PRICE']) * (int) $row['QUANTITY'], $rows)))
        ->toBe(app(PromoCodePricingService::class)->moneyToCents($total));
})->with([
    'repeating fraction' => [3, '100.00', [['33.33', 2], ['33.34', 1]]],
    'single unit' => [1, '0.01', [['0.01', 1]]],
    'zero after full discount' => [3, '0.00', [['0.00', 3]]],
    'sub cent effective price' => [3, '0.01', [['0.00', 2], ['0.01', 1]]],
    'exact division' => [2, '2600.00', [['1300.00', 2]]],
]);

test('invalid item quantity fails before creating a lead', function (): void {
    $order = bitrixRowsOrder();
    $order->items()->update(['quantity' => 0]);

    expect(fn () => app(SendOrderToBitrix::class)->handle(new OrderCreated($order)))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('negative final snapshot fails before creating a lead', function (): void {
    $order = bitrixRowsOrder();
    $order->items()->update(['final_total_snapshot' => '-0.01']);

    expect(fn () => app(SendOrderToBitrix::class)->handle(new OrderCreated($order)))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('client keeps base url and method validation without making requests', function (): void {
    config()->set('shop.bitrix.webhook_url', '');
    expect(fn () => app(BitrixWebhookClient::class)->setLeadProductRows(123, []))->toThrow(InvalidArgumentException::class);
    config()->set('shop.bitrix.webhook_url', 'https://example.test/rest/7/token/');
    expect(fn () => app(BitrixWebhookClient::class)->addLead([], '../crm.lead.add'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});
