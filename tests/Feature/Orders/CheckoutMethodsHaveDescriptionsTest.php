<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\PaymentMethodSetting;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeded checkout methods and payment page cards match the current frontend text exactly', function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);

    expect(DeliveryMethodSetting::query()->count())->toBe(count(DeliveryMethod::cases()))
        ->and(PaymentMethodSetting::query()->count())->toBe(count(PaymentMethod::cases()));

    $transport = DeliveryMethodSetting::query()->where('code', DeliveryMethod::TransportCompany)->firstOrFail();
    $pickup = DeliveryMethodSetting::query()->where('code', DeliveryMethod::Pickup)->firstOrFail();
    $card = PaymentMethodSetting::query()->where('code', PaymentMethod::Card)->firstOrFail();
    $sbp = PaymentMethodSetting::query()->where('code', PaymentMethod::Sbp)->firstOrFail();
    $invoice = PaymentMethodSetting::query()->where('code', PaymentMethod::Invoice)->firstOrFail();
    $cash = PaymentMethodSetting::query()->where('code', PaymentMethod::CashOnDelivery)->firstOrFail();

    expect($transport->title)->toBe('Пункт выдачи СДЕК')
        ->and($transport->description)->toBe('Наш менеджер подберёт ближайший пункт выдачи')
        ->and($pickup->title)->toBe('Самовывоз')
        ->and($pickup->description)->toBe('Если вы из Санкт-Петербурга')
        ->and($card->title)->toBe('Банковская карта')
        ->and($card->description)->toBe('онлайн после подтверждения')
        ->and($sbp->title)->toBe('СБП')
        ->and($sbp->description)->toBe('Перевод по QR или ссылке')
        ->and($invoice->title)->toBe('Счёт для юрлиц')
        ->and($invoice->description)->toBe('С НДС')
        ->and($cash->title)->toBe('При получении')
        ->and($cash->description)->toBe('курьеру / на складе');

    expect($cash->page_title)->toBe('Наличный расчет или оплата картой')
        ->and($cash->page_description)->toBe('При получении товара на нашем складе, в пункте выдачи транспортной компании в вашем городе или при доставке товара по указанному вами адресу')
        ->and($invoice->page_title)->toBe('Безналичный расчёт для юридических лиц')
        ->and($invoice->page_description)->toBe('Осуществляется юридическими лицами путём перечисления денежных средств на расчётный счёт нашей компании на основании выставленного счёта')
        ->and($transport->page_title)->toBe('Доставка транспортной компанией')
        ->and($transport->page_description)->toBe('При получении товара на нашем складе, в пункте выдачи транспортной компании в Вашем городе или при доставке товара по указанному вами адресу')
        ->and(PaymentMethodSetting::query()->whereNotIn('code', [PaymentMethod::Invoice->value, PaymentMethod::CashOnDelivery->value])->whereNotNull('page_title')->exists())->toBeFalse()
        ->and(PaymentMethodSetting::query()->whereNotIn('code', [PaymentMethod::Invoice->value, PaymentMethod::CashOnDelivery->value])->whereNotNull('page_description')->exists())->toBeFalse()
        ->and(DeliveryMethodSetting::query()->where('code', '!=', DeliveryMethod::TransportCompany->value)->whereNotNull('page_title')->exists())->toBeFalse()
        ->and(DeliveryMethodSetting::query()->where('code', '!=', DeliveryMethod::TransportCompany->value)->whereNotNull('page_description')->exists())->toBeFalse();
});

test('repeated checkout seeding preserves manual checkout and payment page descriptions', function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);

    $payment = PaymentMethodSetting::query()->where('code', PaymentMethod::Invoice)->firstOrFail();
    $delivery = DeliveryMethodSetting::query()->where('code', DeliveryMethod::TransportCompany)->firstOrFail();
    $payment->forceFill([
        'description' => 'Ручное описание оплаты',
        'page_title' => 'Ручной заголовок оплаты',
        'page_description' => 'Ручное полное описание оплаты',
    ])->save();
    $delivery->forceFill([
        'description' => 'Ручное описание доставки',
        'page_title' => 'Ручной заголовок доставки',
        'page_description' => 'Ручное полное описание доставки',
    ])->save();

    $this->seed(CheckoutMethodSettingsSeeder::class);

    expect($payment->refresh()->description)->toBe('Ручное описание оплаты')
        ->and($payment->page_title)->toBe('Ручной заголовок оплаты')
        ->and($payment->page_description)->toBe('Ручное полное описание оплаты')
        ->and($delivery->refresh()->description)->toBe('Ручное описание доставки')
        ->and($delivery->page_title)->toBe('Ручной заголовок доставки')
        ->and($delivery->page_description)->toBe('Ручное полное описание доставки');
});
