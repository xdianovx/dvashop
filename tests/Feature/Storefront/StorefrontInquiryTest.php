<?php

use App\Enums\StorefrontInquiryType;
use App\Events\StorefrontInquiryCreated;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontInquiry;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function validInquiryPayload(array $overrides = []): array
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

function signedProductInquiryRoute(Product $product): string
{
    return URL::signedRoute('storefront.inquiries.store', [
        'product_context' => $product->getKey(),
    ]);
}

beforeEach(function (): void {
    Event::fake([StorefrontInquiryCreated::class]);
});

test('ordinary storefront inquiry post stores locally and redirects back', function (): void {
    $this->from('/faq')
        ->withHeader('referer', 'http://localhost/faq')
        ->post(route('storefront.inquiries.store'), validInquiryPayload())
        ->assertRedirect()
        ->assertSessionHas('inquiry_success');

    $inquiry = StorefrontInquiry::query()->sole();

    expect($inquiry->type)->toBe(StorefrontInquiryType::GeneralConsultation)
        ->and($inquiry->name)->toBe('Анна Смирнова')
        ->and($inquiry->email)->toBe('anna@example.test')
        ->and($inquiry->source_url)->toBe(route('faq'))
        ->and($inquiry->product_id)->toBeNull();

    Event::assertDispatched(StorefrontInquiryCreated::class, fn (StorefrontInquiryCreated $event): bool => $event->inquiry->is($inquiry));

    $this->get('/faq')
        ->assertOk()
        ->assertSee('data-inquiry-success-modal', false)
        ->assertSee('data-inquiry-auto-open="success"', false)
        ->assertSee('Спасибо!')
        ->assertSee('Заявка принята. Мы свяжемся с вами.')
        ->assertDontSee('data-inquiry-auto-open="form"', false);
});

test('ordinary storefront inquiry validation returns to the same form without storing a record', function (): void {
    $this->followingRedirects()
        ->from('/about')
        ->post(route('storefront.inquiries.store'), validInquiryPayload([
            'name' => '',
        ]))
        ->assertOk()
        ->assertSee('data-inquiry-auto-open="form"', false)
        ->assertDontSee('data-inquiry-auto-open="success"', false);

    expect(StorefrontInquiry::query()->count())->toBe(0);
});

test('storefront inquiry rejects malformed phone values', function (string $phone): void {
    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload(['phone' => $phone]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone')
        ->assertJsonPath('errors.phone.0', 'Укажите корректный номер телефона.');

    expect(StorefrontInquiry::query()->count())->toBe(0);
})->with([
    'single letter' => ['x'],
    'too few digits' => ['123'],
    'alphabetic phone' => ['телефон'],
]);

test('storefront inquiry accepts supported phone display formats', function (string $phone): void {
    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload(['phone' => $phone]))
        ->assertCreated();

    expect(StorefrontInquiry::query()->sole()->phone)->toBe($phone);
})->with([
    'russian spaced' => ['+7 999 123-45-67'],
    'russian parentheses' => ['8 (999) 123-45-67'],
    'german mobile' => ['+49 151 12345678'],
]);

test('ajax storefront inquiry returns json without a reload', function (): void {
    $this->from('/about')
        ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload(['source_code' => 'about']))
        ->assertCreated()
        ->assertJsonStructure(['message', 'inquiry_id']);

    expect(StorefrontInquiry::query()->sole()->source_code)->toBe('about');
});

test('inquiry type and source code must be an approved pair', function (string $type, string $sourceCode): void {
    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload([
            'type' => $type,
            'source_code' => $sourceCode,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_code');

    expect(StorefrontInquiry::query()->count())->toBe(0);
})->with([
    'general cannot claim partners' => [StorefrontInquiryType::GeneralConsultation->value, 'partners'],
    'partnership cannot claim faq' => [StorefrontInquiryType::Partnership->value, 'faq'],
    'custom part cannot claim about' => [StorefrontInquiryType::CustomPart->value, 'about'],
]);

test('inquiry source url is resolved by the server instead of trusting referer', function (): void {
    $this->withHeader('referer', 'https://attacker.example.test/forged')
        ->post(route('storefront.inquiries.store'), validInquiryPayload())
        ->assertRedirect();

    expect(StorefrontInquiry::query()->sole()->source_url)->toBe(route('faq'));
});

test('storefront inquiry is dispatched only after its database transaction commits', function (): void {
    Queue::fake();
    Event::fake()->except(StorefrontInquiryCreated::class);
    $baselineTransactionLevel = DB::transactionLevel();
    $transactionLevels = [];
    Event::listen(StorefrontInquiryCreated::class, function () use (&$transactionLevels): void {
        $transactionLevels[] = DB::transactionLevel();
    });

    $this->post(route('storefront.inquiries.store'), validInquiryPayload())
        ->assertRedirect();

    expect(StorefrontInquiry::query()->count())->toBe(1)
        ->and($transactionLevels)->toBe([$baselineTransactionLevel]);
});

test('a delivery dispatch failure never rolls back a persisted inquiry or causes user 500', function (): void {
    Event::fake()->except(StorefrontInquiryCreated::class);
    Log::spy();
    config()->set([
        'queue.default' => 'sync',
        'shop.inquiries.email_enabled' => true,
        'shop.inquiries.bitrix_enabled' => false,
        'shop.inquiries.manager_email' => 'manager@example.test',
    ]);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload())
        ->assertCreated();

    expect(StorefrontInquiry::query()->count())->toBe(1)
        ->and(StorefrontInquiry::query()->sole()->email_failed_at)->not->toBeNull();
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Unable to queue storefront inquiry delivery notifications.'
            && isset($context['inquiry_id'])
            && $context['exception'] === 'SMTP unavailable')
        ->once();
});

test('product inquiry snapshots only server data and strips internal metadata', function (): void {
    $product = Product::factory()->create([
        'title' => 'Порог серверный',
        'sku' => 'PRODUCT-SKU',
    ]);
    $variant = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'VARIANT-SKU',
        'options' => [
            ...ProductVariant::technicalOptions(),
            'material' => ['group' => 'Материал', 'value' => 'Оцинковка'],
        ],
        'stock_quantity' => null,
    ]);

    $this->from(route('products.show', $product->slug))
        ->withHeader('referer', 'https://attacker.example.test/forged')
        ->post(signedProductInquiryRoute($product), validInquiryPayload([
            'type' => StorefrontInquiryType::ProductConsultation->value,
            'source_code' => 'product',
            'product_variant_id' => (string) $variant->getKey(),
            'product_id' => 999999,
            'product_title_snapshot' => 'Подделка из браузера',
            'variant_sku_snapshot' => 'FORGED-SKU',
            'options_snapshot' => ['forged' => 'value'],
        ]))
        ->assertRedirect();

    $inquiry = StorefrontInquiry::query()->sole();

    expect($inquiry->product_id)->toBe($product->getKey())
        ->and($inquiry->product_variant_id)->toBe($variant->getKey())
        ->and($inquiry->product_title_snapshot)->toBe('Порог серверный')
        ->and($inquiry->variant_sku_snapshot)->toBe('VARIANT-SKU')
        ->and($inquiry->source_url)->toBe(route('products.show', $product->slug))
        ->and($inquiry->options_snapshot)->toBe([
            'material' => ['group' => 'Материал', 'value' => 'Оцинковка'],
        ])
        ->and(json_encode($inquiry->options_snapshot))->not->toContain('__dvashop');
});

test('forged unavailable product variants are rejected without creating an inquiry', function (mixed $variantId): void {
    $product = Product::factory()->create();

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(signedProductInquiryRoute($product), validInquiryPayload([
            'type' => StorefrontInquiryType::ProductConsultation->value,
            'source_code' => 'product',
            'product_variant_id' => $variantId,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product_variant_id');

    expect(StorefrontInquiry::query()->count())->toBe(0);
})->with([
    'missing id' => [999999],
    'forged string' => ['1abc'],
    'array' => [[]],
]);

test('inactive variant is rejected and non product inquiry cannot inject a variant', function (): void {
    $variant = ProductVariant::factory()->inactive()->create(['stock_quantity' => null]);
    $product = Product::query()->findOrFail($variant->product_id);

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(signedProductInquiryRoute($product), validInquiryPayload([
            'type' => StorefrontInquiryType::ProductConsultation->value,
            'source_code' => 'product',
            'product_variant_id' => $variant->getKey(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product_variant_id');

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload([
            'product_variant_id' => $variant->getKey(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product_variant_id');

    expect(StorefrontInquiry::query()->count())->toBe(0);
});

test('product inquiry rejects an unsigned context and an active variant from another product', function (): void {
    $product = Product::factory()->create();
    $foreignProduct = Product::factory()->create();
    $foreignVariant = ProductVariant::factory()
        ->forProduct($foreignProduct)
        ->default()
        ->create(['stock_quantity' => null]);

    $payload = validInquiryPayload([
        'type' => StorefrontInquiryType::ProductConsultation->value,
        'source_code' => 'product',
        'product_variant_id' => $foreignVariant->getKey(),
    ]);

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store', ['product_context' => $product->getKey()]), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product_context');

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(signedProductInquiryRoute($product), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product_variant_id');

    expect(StorefrontInquiry::query()->count())->toBe(0);
});

test('honeypot rejects spam without creating an inquiry', function (): void {
    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload([
            'company_website' => 'https://spam.example',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_website');

    expect(StorefrontInquiry::query()->count())->toBe(0);
});

test('public inquiry endpoint is rate limited while preserving accepted local records', function (): void {
    foreach (range(1, 5) as $index) {
        $this->withHeaders(['Accept' => 'application/json'])
            ->post(route('storefront.inquiries.store'), validInquiryPayload([
                'email' => "person{$index}@example.test",
            ]))
            ->assertCreated();
    }

    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('storefront.inquiries.store'), validInquiryPayload(['email' => 'limited@example.test']))
        ->assertTooManyRequests();

    expect(StorefrontInquiry::query()->count())->toBe(5);
});

test('storefront ctas use one progressive post form and preserve telephone links', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        HomepageContentSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
    ]);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create(['stock_quantity' => null]);

    foreach ([
        route('faq') => [StorefrontInquiryType::GeneralConsultation, 'faq'],
        route('partners') => [StorefrontInquiryType::Partnership, 'partners'],
        route('about') => [StorefrontInquiryType::GeneralConsultation, 'about'],
    ] as $url => [$type, $sourceCode]) {
        $this->get($url)
            ->assertOk()
            ->assertSee('data-inquiry-open', false)
            ->assertSee('data-inquiry-form', false)
            ->assertSee('name="_token"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('value="'.$type->value.'"', false)
            ->assertSee('name="source_code" value="'.$sourceCode.'"', false)
            ->assertSee('href="tel:', false);
    }

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('class="btn part-buy__consult"', false)
        ->assertSee('Получить консультацию')
        ->assertSee('data-inquiry-product-variant', false)
        ->assertSee('value="'.$variant->getKey().'"', false)
        ->assertSee('product_context='.$product->getKey(), false)
        ->assertSee('signature=', false);
});

test('product inquiry JavaScript keeps the hidden variant synchronized with server published option changes', function (): void {
    $script = file_get_contents(resource_path('js/app.js'));

    expect($script)
        ->toContain("new CustomEvent('storefront:variant-selected'")
        ->toContain('dispatchVariant(String(selectedVariant.variant_id))')
        ->toContain("document.addEventListener('storefront:variant-selected'")
        ->toContain("input.value = event.detail?.variantId || ''")
        ->toContain('syncProductVariant();');
});

test('inquiry JavaScript uses class-only runtime state and switches successful ajax to a separate dialog', function (): void {
    $script = file_get_contents(resource_path('js/app.js'));
    $component = file_get_contents(resource_path('views/components/storefront-inquiry-modal.blade.php'));

    expect($script)
        ->toContain('function createInquiryModalController(modal)')
        ->toContain("const isOpen = () => modal.classList.contains('inquiry-modal--open')")
        ->not->toContain("window.location.hash === '#storefront-inquiry'")
        ->toContain('window.location.hash === `#${modal.id}`')
        ->toContain("trigger.addEventListener('click', (event) =>")
        ->toContain('event.preventDefault();')
        ->toContain("event.key === 'Escape' && isOpen()")
        ->toContain("event.key !== 'Tab'")
        ->toContain('returnFocus.focus()')
        ->toContain('modalController.close(false)')
        ->toContain('successController?.open(trigger)')
        ->not->toContain("success.className = 'inquiry-modal__success'")
        ->and($component)
        ->toContain('data-inquiry-success-modal')
        ->toContain('data-inquiry-auto-open="success"')
        ->toContain('data-inquiry-auto-open="form"')
        ->toContain('data-inquiry-close')
        ->toContain('aria-modal="true"')
        ->toContain('Заявка принята. Мы свяжемся с вами.')
        ->not->toContain('inquiry-modal__success');
});

test('every approved inquiry call to action remains connected on desktop and mobile', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        HomepageContentSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Связаться')
        ->assertSee('data-inquiry-open', false);
    $this->get(route('faq'))
        ->assertOk()
        ->assertSee('Бесплатная консультация')
        ->assertSee('data-inquiry-open', false);
    $this->get(route('partners'))
        ->assertOk()
        ->assertSee('Сотрудничать')
        ->assertSee('Написать нам')
        ->assertSee('data-inquiry-open', false);

    $styles = file_get_contents(resource_path('scss/_partners-page.scss'));
    expect($styles)
        ->toContain(".partners-page__mob-cta {\n    display: flex;")
        ->not->toContain(".partners-page__mob-cta {\n    display: none;");
});
