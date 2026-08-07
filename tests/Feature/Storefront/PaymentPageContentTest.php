<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\PaymentMethodSetting;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('payment page preserves the approved three card presentation contract', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        CheckoutMethodSettingsSeeder::class,
    ]);

    PaymentMethodSetting::query()
        ->where('code', PaymentMethod::CashOnDelivery)
        ->firstOrFail()
        ->forceFill([
            'title' => 'CHECKOUT ONLY PAYMENT TITLE',
            'description' => 'CHECKOUT ONLY PAYMENT DESCRIPTION',
        ])
        ->save();

    DeliveryMethodSetting::query()
        ->where('code', DeliveryMethod::TransportCompany)
        ->firstOrFail()
        ->forceFill([
            'title' => 'CHECKOUT ONLY DELIVERY TITLE',
            'description' => 'CHECKOUT ONLY DELIVERY DESCRIPTION',
            'base_price' => 1490,
        ])
        ->save();

    $response = $this->get(route('payment'))
        ->assertOk()
        ->assertSee('Наличный расчет')
        ->assertSee('или оплата картой')
        ->assertSee('Безналичный расчёт')
        ->assertSee('для юридических лиц')
        ->assertSee('Доставка транспортной компанией')
        ->assertDontSee('CHECKOUT ONLY PAYMENT TITLE')
        ->assertDontSee('CHECKOUT ONLY PAYMENT DESCRIPTION')
        ->assertDontSee('CHECKOUT ONLY DELIVERY TITLE')
        ->assertDontSee('CHECKOUT ONLY DELIVERY DESCRIPTION')
        ->assertDontSee('Стоимость:')
        ->assertSee('payment-page__grid', false);

    $html = $response->getContent();
    $cashPosition = strpos($html, 'Наличный расчет');
    $invoicePosition = strpos($html, 'Безналичный расчёт');
    $deliveryPosition = strpos($html, 'Доставка транспортной компанией');

    expect($cashPosition)->toBeInt()
        ->and($invoicePosition)->toBeInt()
        ->and($deliveryPosition)->toBeInt()
        ->and($cashPosition)->toBeLessThan($invoicePosition)
        ->and($invoicePosition)->toBeLessThan($deliveryPosition);

    PaymentMethodSetting::query()
        ->where('code', PaymentMethod::Invoice)
        ->firstOrFail()
        ->forceFill(['is_active' => false])
        ->save();

    $this->get(route('payment'))
        ->assertOk()
        ->assertSee('Наличный расчет')
        ->assertDontSee('Безналичный расчёт')
        ->assertDontSee('для юридических лиц')
        ->assertSee('Доставка транспортной компанией')
        ->assertDontSee('Стоимость:');

    PaymentMethodSetting::query()
        ->where('code', PaymentMethod::CashOnDelivery)
        ->firstOrFail()
        ->forceFill([
            'page_title' => null,
            'page_description' => null,
        ])
        ->save();

    $this->get(route('payment'))
        ->assertOk()
        ->assertDontSee('Наличный расчет')
        ->assertSee('Доставка транспортной компанией')
        ->assertDontSee('CHECKOUT ONLY PAYMENT TITLE');
});
