<?php

namespace Database\Seeders;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\PaymentMethodSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CheckoutMethodSettingsSeeder extends Seeder
{
    /** @var array<string, array{title:string,position:int}> */
    private const DELIVERY_METHODS = [
        DeliveryMethod::Pickup->value => ['title' => 'Самовывоз', 'position' => 10],
        DeliveryMethod::Courier->value => ['title' => 'Курьер', 'position' => 20],
        DeliveryMethod::TransportCompany->value => ['title' => 'Транспортная компания', 'position' => 30],
        DeliveryMethod::Post->value => ['title' => 'Почта', 'position' => 40],
    ];

    /** @var array<string, array{title:string,position:int}> */
    private const PAYMENT_METHODS = [
        PaymentMethod::Card->value => ['title' => 'Банковская карта', 'position' => 10],
        PaymentMethod::Sbp->value => ['title' => 'СБП', 'position' => 20],
        PaymentMethod::Invoice->value => ['title' => 'Счёт для юрлица', 'position' => 30],
        PaymentMethod::CashOnDelivery->value => ['title' => 'При получении', 'position' => 40],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::DELIVERY_METHODS as $code => $definition) {
                DeliveryMethodSetting::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'title' => $definition['title'],
                        'description' => null,
                        'base_price' => 0,
                        'is_active' => true,
                        'position' => $definition['position'],
                    ],
                );
            }

            foreach (self::PAYMENT_METHODS as $code => $definition) {
                PaymentMethodSetting::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'title' => $definition['title'],
                        'description' => null,
                        'is_active' => true,
                        'position' => $definition['position'],
                    ],
                );
            }
        });
    }
}
