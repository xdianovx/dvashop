<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\PaymentMethodSetting;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('checkout method settings seeder is idempotent and creates exact checkout and payment page defaults', function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);

    $before = [
        'delivery' => DB::table('delivery_method_settings')->orderBy('id')->get([
            'code', 'title', 'description', 'page_title', 'page_description', 'base_price', 'is_active', 'position',
        ])->map(fn ($row): array => (array) $row)->all(),
        'payment' => DB::table('payment_method_settings')->orderBy('id')->get([
            'code', 'title', 'description', 'page_title', 'page_description', 'is_active', 'position',
        ])->map(fn ($row): array => (array) $row)->all(),
    ];

    $this->seed(CheckoutMethodSettingsSeeder::class);

    expect(DeliveryMethodSetting::query()->count())->toBe(count(DeliveryMethod::cases()))
        ->and(PaymentMethodSetting::query()->count())->toBe(count(PaymentMethod::cases()))
        ->and(DeliveryMethodSetting::query()->ordered()->pluck('code')->map->value->all())->toBe([
            'transport_company', 'pickup', 'courier', 'post',
        ])->and(PaymentMethodSetting::query()->ordered()->pluck('code')->map->value->all())->toBe([
            'card', 'sbp', 'invoice', 'cash_on_delivery',
        ])->and(DB::table('delivery_method_settings')->orderBy('id')->get([
            'code', 'title', 'description', 'page_title', 'page_description', 'base_price', 'is_active', 'position',
        ])->map(fn ($row): array => (array) $row)->all())->toBe($before['delivery'])
        ->and(DB::table('payment_method_settings')->orderBy('id')->get([
            'code', 'title', 'description', 'page_title', 'page_description', 'is_active', 'position',
        ])->map(fn ($row): array => (array) $row)->all())->toBe($before['payment']);

    expect(DB::table('delivery_method_settings')->where('code', DeliveryMethod::TransportCompany->value)->first())->toMatchObject([
        'title' => 'Пункт выдачи СДЕК',
        'description' => 'Наш менеджер подберёт ближайший пункт выдачи',
        'page_title' => 'Доставка транспортной компанией',
        'page_description' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в Вашем городе или при доставке товара по указанному вами адресу',
        'base_price' => 0,
        'is_active' => 1,
        'position' => 10,
    ])->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::Pickup->value)->first())->toMatchObject([
        'title' => 'Самовывоз',
        'description' => 'Если вы из Санкт-Петербурга',
        'page_title' => null,
        'page_description' => null,
        'base_price' => 0,
        'is_active' => 1,
        'position' => 20,
    ])->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::Courier->value)->first())->toMatchObject([
        'title' => 'Курьер',
        'description' => null,
        'page_title' => null,
        'page_description' => null,
        'base_price' => 0,
        'is_active' => 0,
        'position' => 30,
    ])->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::Post->value)->first())->toMatchObject([
        'title' => 'Почта',
        'description' => null,
        'page_title' => null,
        'page_description' => null,
        'base_price' => 0,
        'is_active' => 0,
        'position' => 40,
    ]);

    expect(DB::table('payment_method_settings')->where('code', PaymentMethod::Card->value)->first())->toMatchObject([
        'title' => 'Банковская карта',
        'description' => 'онлайн после подтверждения',
        'page_title' => null,
        'page_description' => null,
        'is_active' => 1,
        'position' => 10,
    ])->and(DB::table('payment_method_settings')->where('code', PaymentMethod::Sbp->value)->first())->toMatchObject([
        'title' => 'СБП',
        'description' => 'Перевод по QR или ссылке',
        'page_title' => null,
        'page_description' => null,
        'is_active' => 1,
        'position' => 20,
    ])->and(DB::table('payment_method_settings')->where('code', PaymentMethod::Invoice->value)->first())->toMatchObject([
        'title' => 'Счёт для юрлиц',
        'description' => 'С НДС',
        'page_title' => 'Безналичный расчёт для юридических лиц',
        'page_description' => 'Осуществляется юридическими лицами путём перечисления денежных средств на расчётный счёт нашей компании на основании выставленного счёта',
        'is_active' => 1,
        'position' => 30,
    ])->and(DB::table('payment_method_settings')->where('code', PaymentMethod::CashOnDelivery->value)->first())->toMatchObject([
        'title' => 'При получении',
        'description' => 'курьеру / на складе',
        'page_title' => 'Наличный расчет или оплата картой',
        'page_description' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в вашем городе или при доставке товара по указанному вами адресу',
        'is_active' => 1,
        'position' => 40,
    ]);
});

test('checkout method seeder preserves old non blank defaults and fills only null or blank fields', function (): void {
    foreach ([
        DeliveryMethod::Pickup->value => ['Самовывоз', 0, true, 10],
        DeliveryMethod::Courier->value => ['Курьер', 0, true, 20],
        DeliveryMethod::TransportCompany->value => ['Транспортная компания', 0, true, 30],
        DeliveryMethod::Post->value => ['Почта', 0, true, 40],
    ] as $code => [$title, $basePrice, $isActive, $position]) {
        DeliveryMethodSetting::query()->create([
            'code' => $code,
            'title' => $title,
            'description' => $code === DeliveryMethod::Pickup->value ? '   ' : null,
            'page_title' => $code === DeliveryMethod::TransportCompany->value ? '   ' : null,
            'page_description' => null,
            'base_price' => $basePrice,
            'is_active' => $isActive,
            'position' => $position,
        ]);
    }

    foreach ([
        PaymentMethod::Card->value => ['Банковская карта', 10],
        PaymentMethod::Sbp->value => ['СБП', 20],
        PaymentMethod::Invoice->value => ['Счёт для юрлица', 30],
        PaymentMethod::CashOnDelivery->value => ['При получении', 40],
    ] as $code => [$title, $position]) {
        PaymentMethodSetting::query()->create([
            'code' => $code,
            'title' => $title,
            'description' => null,
            'page_title' => $code === PaymentMethod::CashOnDelivery->value ? '   ' : null,
            'page_description' => null,
            'is_active' => true,
            'position' => $position,
        ]);
    }

    $this->seed(CheckoutMethodSettingsSeeder::class);

    expect(DB::table('delivery_method_settings')->where('code', DeliveryMethod::TransportCompany->value)->first())->toMatchObject([
        'title' => 'Транспортная компания',
        'description' => 'Наш менеджер подберёт ближайший пункт выдачи',
        'page_title' => 'Доставка транспортной компанией',
        'page_description' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в Вашем городе или при доставке товара по указанному вами адресу',
        'base_price' => 0,
        'is_active' => 1,
        'position' => 30,
    ])->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::Courier->value)->first())->toMatchObject([
        'title' => 'Курьер',
        'description' => null,
        'base_price' => 0,
        'is_active' => 1,
        'position' => 20,
    ])->and(DB::table('delivery_method_settings')->where('code', DeliveryMethod::Post->value)->first())->toMatchObject([
        'title' => 'Почта',
        'description' => null,
        'base_price' => 0,
        'is_active' => 1,
        'position' => 40,
    ])->and(DB::table('payment_method_settings')->where('code', PaymentMethod::Invoice->value)->first())->toMatchObject([
        'title' => 'Счёт для юрлица',
        'description' => 'С НДС',
        'page_title' => 'Безналичный расчёт для юридических лиц',
        'page_description' => 'Осуществляется юридическими лицами путём перечисления денежных средств на расчётный счёт нашей компании на основании выставленного счёта',
        'is_active' => 1,
        'position' => 30,
    ]);
});

test('checkout method settings seeder preserves every manual business field and unrelated row', function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);

    DeliveryMethodSetting::query()->where('code', DeliveryMethod::Courier)->update([
        'title' => 'Своя доставка',
        'description' => 'Ручное описание оформления',
        'page_title' => 'Ручной заголовок страницы',
        'page_description' => 'Ручное полное описание страницы',
        'base_price' => 777.25,
        'is_active' => true,
        'position' => 99,
    ]);
    PaymentMethodSetting::query()->where('code', PaymentMethod::Sbp)->update([
        'title' => 'Своя оплата',
        'description' => 'Ручное описание оформления',
        'page_title' => 'Ручной заголовок страницы',
        'page_description' => 'Ручное полное описание страницы',
        'is_active' => false,
        'position' => 88,
    ]);
    DB::table('delivery_method_settings')->insert([
        'code' => 'legacy_delivery',
        'title' => 'Legacy',
        'description' => 'Legacy description',
        'page_title' => 'Legacy page title',
        'page_description' => 'Legacy page description',
        'base_price' => 5,
        'is_active' => false,
        'position' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('payment_method_settings')->insert([
        'code' => 'legacy_payment',
        'title' => 'Legacy',
        'description' => 'Legacy description',
        'page_title' => 'Legacy page title',
        'page_description' => 'Legacy page description',
        'is_active' => false,
        'position' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(CheckoutMethodSettingsSeeder::class);

    expect(DB::table('delivery_method_settings')->where('code', 'courier')->first())->toMatchObject([
        'title' => 'Своя доставка',
        'description' => 'Ручное описание оформления',
        'page_title' => 'Ручной заголовок страницы',
        'page_description' => 'Ручное полное описание страницы',
        'base_price' => 777.25,
        'is_active' => 1,
        'position' => 99,
    ])->and(DB::table('payment_method_settings')->where('code', 'sbp')->first())->toMatchObject([
        'title' => 'Своя оплата',
        'description' => 'Ручное описание оформления',
        'page_title' => 'Ручной заголовок страницы',
        'page_description' => 'Ручное полное описание страницы',
        'is_active' => 0,
        'position' => 88,
    ])->and(DB::table('delivery_method_settings')->where('code', 'legacy_delivery')->first())->toMatchObject([
        'title' => 'Legacy',
        'description' => 'Legacy description',
        'page_title' => 'Legacy page title',
        'page_description' => 'Legacy page description',
        'base_price' => 5,
        'is_active' => 0,
        'position' => 500,
    ])->and(DB::table('payment_method_settings')->where('code', 'legacy_payment')->first())->toMatchObject([
        'title' => 'Legacy',
        'description' => 'Legacy description',
        'page_title' => 'Legacy page title',
        'page_description' => 'Legacy page description',
        'is_active' => 0,
        'position' => 500,
    ]);
});
