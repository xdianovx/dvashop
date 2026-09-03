<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Enums\StorefrontInquiryType;
use App\Events\OrderCreated;
use App\Events\StorefrontInquiryCreated;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Services\CartManager;
use App\Services\Integrations\UisPayloadBuilder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function uisInquiryPayload(array $overrides = []): array
{
    return [
        'type' => StorefrontInquiryType::GeneralConsultation->value,
        'name' => 'Анна Смирнова',
        'phone' => '+7 999 111-22-33',
        'email' => 'ANNA@EXAMPLE.TEST',
        'message' => 'Нужна консультация',
        'source_code' => 'faq',
        'company_website' => '',
        ...$overrides,
    ];
}

function uisCartRequest(Cart $cart): Request
{
    return Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
}

beforeEach(function (): void {
    Event::fake([StorefrontInquiryCreated::class, OrderCreated::class]);
});

test('confirmed ajax inquiry returns a server snapshot UIS payload while validation errors do not', function (): void {
    $response = $this->withHeaders([
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ])->post(route('storefront.inquiries.store'), uisInquiryPayload());

    $response->assertCreated()
        ->assertJsonPath('uis.name', 'Анна Смирнова')
        ->assertJsonPath('uis.email', 'anna@example.test')
        ->assertJsonPath('uis.phone', '+7 999 111-22-33')
        ->assertJsonPath('uis.message', fn (string $message): bool => str_contains($message, 'Тип заявки: Общая консультация')
            && str_contains($message, 'Источник: faq')
            && str_contains($message, 'URL: '.route('faq'))
            && str_contains($message, 'Сообщение: Нужна консультация'));

    $correlationKey = (string) $response->json('uis.correlationKey');
    expect($correlationKey)->toStartWith('inquiry:')
        ->not->toBe('inquiry:'.$response->json('inquiry_id'));

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), uisInquiryPayload(['phone' => 'invalid']))
        ->assertUnprocessable()
        ->assertJsonMissingPath('uis');
});

test('ordinary inquiry exposes safe one-time UIS JSON without breaking its success dialog', function (): void {
    $this->seed([ShopSettingsSeeder::class, FaqSeeder::class]);
    $unsafeName = '</script><script>window.compromised=true</script>';

    $this->from(route('faq'))
        ->post(route('storefront.inquiries.store'), uisInquiryPayload(['name' => $unsafeName]))
        ->assertRedirect()
        ->assertSessionHas('inquiry_success')
        ->assertSessionHas('uis_success_payload', fn (array $payload): bool => $payload['name'] === $unsafeName);

    $this->get(route('faq'))
        ->assertOk()
        ->assertSee('data-inquiry-success-modal', false)
        ->assertSee('data-inquiry-auto-open="success"', false)
        ->assertSee('data-uis-success-payload', false)
        ->assertDontSee($unsafeName, false)
        ->assertSee('\\u003C\\/script\\u003E\\u003Cscript\\u003Ewindow.compromised=true\\u003C\\/script\\u003E', false);

    $this->get(route('faq'))
        ->assertOk()
        ->assertDontSee('data-uis-success-payload', false)
        ->assertDontSee('data-inquiry-auto-open="success"', false);
});

test('checkout flashes a one-time UIS payload built from the saved order snapshots and totals', function (): void {
    $delivery = DeliveryMethodSetting::factory()->create([
        'code' => DeliveryMethod::Pickup,
        'title' => 'Самовывоз из сохранённой настройки',
        'base_price' => 490,
        'price_mode' => DeliveryPriceMode::Fixed,
        'is_active' => true,
    ]);
    $payment = PaymentMethodSetting::factory()->create([
        'code' => PaymentMethod::Sbp,
        'title' => 'СБП из сохранённой настройки',
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->default()->create(['price' => 1500, 'stock_quantity' => null]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(uisCartRequest($cart), $variant->getKey(), 2);

    $response = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->post(route('checkout.store'), [
            'customer_name' => 'Иван Петров',
            'customer_phone' => '+79990000000',
            'customer_email' => 'ivan@example.test',
            'customer_city' => 'Москва',
            'delivery_method' => $delivery->code->value,
            'payment_method' => $payment->code->value,
            'agree_terms' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHas('uis_success_payload');

    $order = Order::query()->sole();
    $payload = session('uis_success_payload');

    expect($payload)->toBe(app(UisPayloadBuilder::class)->forOrder($order->load('items')))
        ->and($payload['correlationKey'])->toBe('order:'.$order->number)
        ->and($payload['name'])->toBe('Иван Петров')
        ->and($payload['email'])->toBe('ivan@example.test')
        ->and($payload['phone'])->toBe('+79990000000')
        ->and($payload['message'])->toContain('Заказ: '.$order->number)
        ->toContain('Город: Москва')
        ->toContain('Способ доставки: Самовывоз из сохранённой настройки')
        ->toContain('Оплата: СБП из сохранённой настройки')
        ->toContain('Товары: 3 000,00 ₽')
        ->toContain('Доставка: 490 ₽')
        ->toContain('Итого: 3 490,00 ₽');

    $location = (string) $response->headers->get('Location');
    $this->get($location)->assertOk()->assertSee('data-uis-success-payload', false);
    $this->get($location)->assertOk()->assertDontSee('data-uis-success-payload', false);
});

test('order UIS message includes stored promo and non-final delivery snapshots', function (): void {
    $order = Order::factory()->create([
        'number' => 'DVS-UIS-SNAPSHOT',
        'promo_code_snapshot' => 'SAVE400',
        'delivery_method_title_snapshot' => 'Транспортная компания',
        'payment_method_title_snapshot' => 'Оплата по счёту',
        'delivery_price_mode_snapshot' => DeliveryPriceMode::OnRequest,
        'subtotal' => 3000,
        'discount_total' => 400,
        'delivery_price' => 0,
        'total' => 2600,
        'total_is_final' => false,
    ]);

    $message = app(UisPayloadBuilder::class)->forOrder($order)['message'];

    expect($message)->toContain('Промокод: SAVE400')
        ->toContain('Скидка: 400,00 ₽')
        ->toContain('Доставка: Доставка рассчитывается отдельно')
        ->toContain('Сумма товаров (без доставки): 2 600,00 ₽');
});

test('UIS browser hook is bounded fail-open duplicate-safe and isolated from utility forms', function (): void {
    $module = file_get_contents(resource_path('js/modules/uis-form-tracking.js'));
    $app = file_get_contents(resource_path('js/app.js'));
    $utilityViews = collect([
        'views/components/search.blade.php',
        'views/components/favorite-toggle.blade.php',
        'views/components/promo-code-form.blade.php',
        'views/components/cart-item.blade.php',
        'views/components/product-card.blade.php',
        'views/cart.blade.php',
    ])->map(fn (string $path): string => file_get_contents(resource_path($path)))->implode("\n");

    expect($module)
        ->toContain('UIS_WAIT_TIMEOUT_MS = 5000')
        ->toContain('UIS_POLL_INTERVAL_MS = 200')
        ->toContain("typeof window.Comagic?.addOfflineRequest === 'function'")
        ->toContain('window.Comagic.addOfflineRequest({')
        ->toContain('trackedCorrelationKeys = new Set()')
        ->toContain('window.sessionStorage')
        ->toContain('try {')
        ->not->toContain('throw ')
        ->and($app)
        ->toContain("import { trackUisOfflineRequest } from './modules/uis-form-tracking.js'")
        ->toContain('trackUisOfflineRequest(payload.uis)')
        ->toContain('if (!response.ok)')
        ->and(substr_count($app, 'trackUisOfflineRequest(payload.uis)'))->toBe(1)
        ->and($utilityViews)->not->toContain('trackUisOfflineRequest')
        ->not->toContain('data-uis-success-payload');
});

test('UIS counter loader is absent without a key and uses only a configured valid public key', function (): void {
    $this->seed([ShopSettingsSeeder::class, FaqSeeder::class]);

    config()->set('shop.uis.public_key', null);
    $this->get(route('faq'))->assertOk()->assertDontSee('app.uiscom.ru/static/cs.min.js', false);

    config()->set('shop.uis.public_key', 'test_Public-Key_123');
    $this->get(route('faq'))
        ->assertOk()
        ->assertSee('https://app.uiscom.ru/static/cs.min.js?k=test_Public-Key_123', false);

    config()->set('shop.uis.public_key', 'invalid-key"><script>');
    $this->get(route('faq'))->assertOk()->assertDontSee('app.uiscom.ru/static/cs.min.js', false);
});
