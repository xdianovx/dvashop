<?php

use App\Enums\StockStatus;
use App\Enums\StorefrontInquiryType;
use App\Events\OrderCreated;
use App\Events\StorefrontInquiryCreated;
use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StorefrontInquiry;
use App\Services\Integrations\UisPayloadBuilder;
use App\Services\Storefront\StorefrontInquiryService;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function retiredCheckoutInquiryPayload(): array
{
    return [
        'type' => StorefrontInquiryType::GeneralConsultation->value,
        'source_code' => 'checkout',
        'name' => 'Покупатель',
        'phone' => '+7 999 111-22-33',
        'email' => 'buyer@example.test',
        'message' => 'Заказать в 1 клик',
        'company_website' => '',
    ];
}

test('retired checkout inquiry rejects the original public payload without side effects', function (bool $ajax): void {
    Event::fake([StorefrontInquiryCreated::class, OrderCreated::class]);
    Mail::fake();
    Queue::fake();
    Http::preventStrayRequests();
    $this->mock(UisPayloadBuilder::class)->shouldNotReceive('forInquiry');
    config(['shop.inquiries.email_enabled' => true, 'shop.inquiries.bitrix_enabled' => true]);

    if ($ajax) {
        $this->postJson(route('storefront.inquiries.store'), retiredCheckoutInquiryPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('source_code')->assertJsonMissingPath('uis');
    } else {
        $this->from(route('checkout.show'))
            ->post(route('storefront.inquiries.store'), retiredCheckoutInquiryPayload())
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors('source_code', errorBag: 'inquiry')
            ->assertSessionMissing('inquiry_success')
            ->assertSessionMissing('uis_success_payload');
    }

    expect(StorefrontInquiry::count())->toBe(0)
        ->and(Order::count())->toBe(0)
        ->and(Cart::count())->toBe(0);
    Event::assertNotDispatched(StorefrontInquiryCreated::class);
    Event::assertNotDispatched(OrderCreated::class);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Http::assertNothingSent();
})->with(['ordinary POST' => false, 'AJAX POST' => true]);

test('retired checkout source is rejected by the service before persistence or dispatch', function (): void {
    Event::fake([StorefrontInquiryCreated::class]);
    expect(fn () => app(StorefrontInquiryService::class)->create(retiredCheckoutInquiryPayload()))
        ->toThrow(ValidationException::class);
    expect(StorefrontInquiry::count())->toBe(0)->and(Order::count())->toBe(0);
    Event::assertNotDispatched(StorefrontInquiryCreated::class);
});

test('product cart and checkout pages contain no one click modal or purchase CTA', function (): void {
    $variant = ProductVariant::factory()->default()->create();

    $this->get(route('products.show', $variant->product->slug))
        ->assertOk()
        ->assertDontSee('Заказать в 1 клик')
        ->assertDontSee('data-inquiry-modal', false)
        ->assertDontSee('data-inquiry-form', false);

    expect(Cart::count())->toBe(0);

    foreach ([route('cart.show'), route('checkout.show')] as $url) {
        $this->get($url)->assertOk()
            ->assertDontSee('Заказать в 1 клик')
            ->assertDontSee('data-inquiry-modal', false)
            ->assertDontSee('data-inquiry-form', false);
    }
});

test('shared product cards expose only the details cta for every variant shape', function (string $kind): void {
    $variant = ProductVariant::factory()->default()->create([
        'price' => 1000,
        'stock_status' => $kind === 'unavailable' ? StockStatus::OutOfStock : StockStatus::InStock,
        'stock_quantity' => $kind === 'unavailable' ? 0 : 5,
    ]);
    if ($kind === 'multiple') {
        ProductVariant::factory()->forProduct($variant->product)->create(['price' => 1100]);
    }
    $card = ProductCardViewModel::fromProduct($variant->product->fresh());
    $html = $this->blade('<x-product-card :product="$product" />', ['product' => $card]);
    $html->assertDontSee('Заказать в 1 клик')->assertSee('Подробнее')
        ->assertSee('href="'.$card->url.'"', false)
        ->assertDontSee('data-cart-add', false)
        ->assertDontSee('product-card__buy', false)
        ->assertDontSee('Добавить в корзину')
        ->assertDontSee('В корзину');
})->with(['single', 'multiple', 'unavailable']);
