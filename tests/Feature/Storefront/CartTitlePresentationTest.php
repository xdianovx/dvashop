<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Events\OrderCreated;
use App\Listeners\SendOrderToBitrix;
use App\Mail\CustomerOrderCreatedMail;
use App\Mail\ManagerOrderCreatedMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function titlePresentationFixture(string $title = 'Порог для Acura Integra 3 1993 - 2001 Седан 4 дв.'): array
{
    DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Pickup, 'is_active' => true]);
    PaymentMethodSetting::factory()->create(['code' => PaymentMethod::Sbp, 'is_active' => true]);
    $options = [
        'profile' => ['group' => 'Профиль', 'value' => 'Полный'],
        'material' => ['group' => 'Материал', 'value' => 'Оцинковка'],
        'side' => ['group' => 'Положение', 'value' => 'Левый'],
        'thickness' => ['group' => 'Толщина металла', 'value' => '1 мм'],
    ];
    $summary = 'Профиль: Полный; Материал: Оцинковка; Положение: Левый; Толщина металла: 1 мм';
    $product = Product::factory()->create(['title' => $title]);
    $variant = ProductVariant::factory()->forProduct($product)->create([
        'title' => $summary, 'options' => $options, 'price' => 2500, 'stock_quantity' => null,
    ]);
    $cart = Cart::factory()->create();
    $item = app(CartManager::class)->addItem(
        Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]),
        $variant->getKey(), 2,
    );

    return [$cart, $item, $product, $variant, $title, $summary];
}

test('cart and checkout show only base titles above saved options for existing combined snapshots', function (): void {
    [$cart, $item, $product, $variant, $title, $summary] = titlePresentationFixture();
    // Existing generated titles can have a different option order from the public snapshot.
    $item->update(['title_snapshot' => $title.' — Профиль: Полный; Положение: Левый; Материал: Оцинковка; Толщина металла: 1 мм']);
    $before = $item->getAttributes();

    foreach (['cart.show' => 'cart-item', 'checkout.show' => 'checkout-order'] as $route => $class) {
        $response = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route($route))->assertOk();
        $html = $response->getContent();
        expect($html)->toContain('class="'.$class.'__name">'.$title.'<')
            ->toContain('class="'.$class.'__opts">'.$summary.'</p>')
            ->not->toContain($before['title_snapshot'])
            ->and(substr_count($html, $summary))->toBe(1);
        if ($route === 'cart.show') {
            $response->assertSee(route('cart.items.update', $item), false)
                ->assertSee(route('cart.items.destroy', $item), false)
                ->assertSee(route('products.show', $product->slug), false)
                ->assertSee('data-cart-item-id="'.$item->getKey().'"', false)
                ->assertSee('data-cart-item-quantity>2</span>', false)
                ->assertSee('5 000 ₽');
        } else {
            $response->assertSee('2 шт. × 2 500 ₽');
        }
    }
    expect($item->refresh()->getAttributes())->toBe($before)
        ->and($item->product_variant_id)->toBe($variant->getKey())
        ->and($item->lineTotal())->toBe(5000.0);
});

test('deleted product falls back to the exact saved summary without breaking either page', function (): void {
    [$cart, $item, $product, , $title, $summary] = titlePresentationFixture();
    $product->forceDeleteQuietly();
    expect($item->refresh()->product_id)->toBeNull()
        ->and($item->storefrontTitle())->toBe($title);
    foreach (['cart.show' => 'cart-item', 'checkout.show' => 'checkout-order'] as $route => $class) {
        $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route($route))->assertOk()
            ->assertSee('class="'.$class.'__name">'.$title.'<', false)
            ->assertSee('class="'.$class.'__opts">'.$summary.'</p>', false);
    }
    expect($item->refresh()->title_snapshot)->toBe($title.' — '.$summary);
});

test('fallback never truncates arbitrary dashes or a nonmatching option suffix', function (string $title, array $options, string $expected): void {
    $item = new CartItem(['title_snapshot' => $title, 'options_snapshot' => $options]);
    $item->setRelation('product', null);
    expect($item->storefrontTitle())->toBe($expected)
        ->and($item->title_snapshot)->toBe($title);
})->with([
    ['Порог — усиленный — Материал: Сталь', ['Материал' => 'Сталь'], 'Порог — усиленный'],
    ['Порог — усиленный', ['Материал' => 'Сталь'], 'Порог — усиленный'],
    ['Порог — Материал: Сталь', ['Материал' => 'Оцинковка'], 'Порог — Материал: Сталь'],
    ['Порог — усиленный', [], 'Порог — усиленный'],
    [' — Материал: Сталь', ['Материал' => 'Сталь'], ' — Материал: Сталь'],
]);

test('storefront title leaves order thanks email and Bitrix snapshots intact', function (): void {
    Http::preventStrayRequests();
    Event::fake([OrderCreated::class]);
    [$cart, $item, $product, , $title, $summary] = titlePresentationFixture();
    $snapshotTitle = $item->title_snapshot;
    $snapshotOptions = $item->options_snapshot;
    expect($item->storefrontTitle())->toBe($title);
    $response = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->post(route('checkout.store'), [
        'customer_name' => 'Тестовый покупатель', 'customer_phone' => '+79990000000',
        'customer_city' => 'Москва', 'customer_address' => 'Тестовый адрес',
        'delivery_method' => 'pickup', 'payment_method' => 'sbp', 'agree_terms' => '1',
    ])->assertRedirect();
    $order = Order::query()->sole()->load('items');
    $orderItem = $order->items->sole();
    expect($orderItem->title_snapshot)->toBe($snapshotTitle)
        ->and($orderItem->options_snapshot)->toBe($snapshotOptions)
        ->and($orderItem->price_snapshot)->toBe('2500.00')
        ->and($orderItem->quantity)->toBe(2);
    $before = $orderItem->getAttributes();
    $product->update(['title' => 'Изменённый каталог']);
    $this->get($response->headers->get('Location'))->assertOk()->assertSee($snapshotTitle)->assertSee($summary);
    foreach ([CustomerOrderCreatedMail::class, ManagerOrderCreatedMail::class] as $mail) {
        expect((new $mail($order, 'Тестовый магазин'))->render())->toContain($snapshotTitle, $summary);
    }
    config(['shop.orders.bitrix_enabled' => true, 'shop.bitrix.webhook_url' => 'https://example.test/rest/1/test/']);
    Http::fake(['example.test/*' => Http::response(['result' => 123])]);
    app(SendOrderToBitrix::class)->handle(new OrderCreated($order));
    Http::assertSent(fn ($request): bool => str_contains($request['fields']['SOURCE_DESCRIPTION'] ?? '', $snapshotTitle));
    expect($orderItem->refresh()->getAttributes())->toBe($before);
});

test('checkout title loading does not add a product query for each cart item', function (): void {
    [$cart] = titlePresentationFixture();
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token);
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->get(route('checkout.show'))->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };
    $one = $countQueries();
    foreach (range(1, 9) as $index) {
        $variant = ProductVariant::factory()->create(['stock_quantity' => null]);
        app(CartManager::class)->addItem(Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]), $variant->getKey());
    }
    expect($countQueries())->toBeLessThanOrEqual($one + 2);
});

test('renaming a product updates cart and checkout headings while preserving snapshots', function (): void {
    [$cart, $item, $product, , , $summary] = titlePresentationFixture('Старое название');
    $before = $item->getAttributes();
    $product->update(['title' => 'Новое название']);

    foreach (['cart.show' => 'cart-item', 'checkout.show' => 'checkout-order'] as $route => $class) {
        $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route($route))->assertOk()
            ->assertSee('class="'.$class.'__name">Новое название<', false)
            ->assertSee('class="'.$class.'__opts">'.$summary.'</p>', false)
            ->assertDontSee('Старое название');
    }
    expect($item->refresh()->getAttributes())->toBe($before);
});

test('live product title is authoritative regardless of snapshot text', function (): void {
    $item = new CartItem([
        'title_snapshot' => 'Порог — Комплект левый/правый',
        'options_snapshot' => ['Материал' => 'Сталь'],
    ]);
    $item->setRelation('product', new Product(['title' => 'Порог']));
    expect($item->storefrontTitle())->toBe('Порог');
    $item->setRelation('product', new Product(['title' => 'Новое название']));
    expect($item->storefrontTitle())->toBe('Новое название');
});

test('stale variant title never appears in cart or checkout heading after option rename', function (): void {
    [$cart, $item, $product, $variant] = titlePresentationFixture('Порог для Acura');
    $variant->update(['title' => 'Профиль: Полный']);
    $item->update(['title_snapshot' => 'Порог для Acura — Профиль: Полный', 'options_snapshot' => ['Профиль' => 'Увеличенный']]);
    $before = $item->getAttributes();
    foreach (['cart.show' => 'cart-item', 'checkout.show' => 'checkout-order'] as $route => $class) {
        $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route($route))->assertOk()
            ->assertSee('class="'.$class.'__name">Порог для Acura<', false)
            ->assertSee('class="'.$class.'__opts">Профиль: Увеличенный</p>', false)
            ->assertDontSee('Профиль: Полный');
    }
    expect($item->refresh()->getAttributes())->toBe($before);
});
