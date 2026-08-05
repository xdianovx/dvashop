<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\PaymentMethodSetting;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('checkout method settings seeder is idempotent and creates only enum backed defaults', function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);
    $this->seed(CheckoutMethodSettingsSeeder::class);

    expect(DeliveryMethodSetting::query()->count())->toBe(count(DeliveryMethod::cases()))
        ->and(PaymentMethodSetting::query()->count())->toBe(count(PaymentMethod::cases()))
        ->and(DeliveryMethodSetting::query()->ordered()->pluck('code')->map->value->all())->toBe([
            'pickup',
            'courier',
            'transport_company',
            'post',
        ])
        ->and(PaymentMethodSetting::query()->ordered()->pluck('code')->map->value->all())->toBe([
            'card',
            'sbp',
            'invoice',
            'cash_on_delivery',
        ])
        ->and(DeliveryMethodSetting::query()->where('code', DeliveryMethod::Pickup)->value('base_price'))->toBe('0.00');
});

test('checkout method settings seeder preserves manual and unrelated rows', function (): void {
    $this->seed(CheckoutMethodSettingsSeeder::class);

    DeliveryMethodSetting::query()->where('code', DeliveryMethod::Courier)->update([
        'title' => 'Своя доставка',
        'description' => 'Ручное описание',
        'base_price' => 777.25,
        'is_active' => false,
        'position' => 99,
    ]);
    PaymentMethodSetting::query()->where('code', PaymentMethod::Sbp)->update([
        'title' => 'Своя оплата',
        'description' => 'Ручное описание',
        'is_active' => false,
        'position' => 88,
    ]);
    DB::table('delivery_method_settings')->insert([
        'code' => 'legacy_delivery',
        'title' => 'Legacy',
        'base_price' => 5,
        'is_active' => false,
        'position' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('payment_method_settings')->insert([
        'code' => 'legacy_payment',
        'title' => 'Legacy',
        'is_active' => false,
        'position' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(CheckoutMethodSettingsSeeder::class);

    expect(DB::table('delivery_method_settings')->where('code', 'courier')->first())
        ->toMatchObject([
            'title' => 'Своя доставка',
            'description' => 'Ручное описание',
            'base_price' => 777.25,
            'is_active' => 0,
            'position' => 99,
        ])
        ->and(DB::table('payment_method_settings')->where('code', 'sbp')->first())
        ->toMatchObject([
            'title' => 'Своя оплата',
            'description' => 'Ручное описание',
            'is_active' => 0,
            'position' => 88,
        ])
        ->and(DB::table('delivery_method_settings')->where('code', 'legacy_delivery')->exists())->toBeTrue()
        ->and(DB::table('payment_method_settings')->where('code', 'legacy_payment')->exists())->toBeTrue();
});
