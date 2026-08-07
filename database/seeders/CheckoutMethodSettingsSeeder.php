<?php

namespace Database\Seeders;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\PaymentMethodSetting;
use Database\Seeders\Concerns\FillsMissingSeederAttributes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CheckoutMethodSettingsSeeder extends Seeder
{
    use FillsMissingSeederAttributes;

    /** @var array<string, array<string, mixed>> */
    private const DELIVERY_METHODS = [
        DeliveryMethod::TransportCompany->value => [
            'title' => 'Пункт выдачи СДЕК',
            'description' => 'Наш менеджер подберёт ближайший пункт выдачи',
            'page_title' => 'Доставка транспортной компанией',
            'page_description' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в Вашем городе или при доставке товара по указанному вами адресу',
            'base_price' => 0,
            'is_active' => true,
            'position' => 10,
        ],
        DeliveryMethod::Pickup->value => [
            'title' => 'Самовывоз',
            'description' => 'Если вы из Санкт-Петербурга',
            'page_title' => null,
            'page_description' => null,
            'base_price' => 0,
            'is_active' => true,
            'position' => 20,
        ],
        DeliveryMethod::Courier->value => [
            'title' => 'Курьер',
            'description' => null,
            'page_title' => null,
            'page_description' => null,
            'base_price' => 0,
            'is_active' => false,
            'position' => 30,
        ],
        DeliveryMethod::Post->value => [
            'title' => 'Почта',
            'description' => null,
            'page_title' => null,
            'page_description' => null,
            'base_price' => 0,
            'is_active' => false,
            'position' => 40,
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private const PAYMENT_METHODS = [
        PaymentMethod::Card->value => [
            'title' => 'Банковская карта',
            'description' => 'онлайн после подтверждения',
            'page_title' => null,
            'page_description' => null,
            'is_active' => true,
            'position' => 10,
        ],
        PaymentMethod::Sbp->value => [
            'title' => 'СБП',
            'description' => 'Перевод по QR или ссылке',
            'page_title' => null,
            'page_description' => null,
            'is_active' => true,
            'position' => 20,
        ],
        PaymentMethod::Invoice->value => [
            'title' => 'Счёт для юрлиц',
            'description' => 'С НДС',
            'page_title' => 'Безналичный расчёт для юридических лиц',
            'page_description' => 'Осуществляется юридическими лицами путём перечисления денежных средств на расчётный счёт нашей компании на основании выставленного счёта',
            'is_active' => true,
            'position' => 30,
        ],
        PaymentMethod::CashOnDelivery->value => [
            'title' => 'При получении',
            'description' => 'курьеру / на складе',
            'page_title' => 'Наличный расчет или оплата картой',
            'page_description' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в вашем городе или при доставке товара по указанному вами адресу',
            'is_active' => true,
            'position' => 40,
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::DELIVERY_METHODS as $code => $definition) {
                $setting = DeliveryMethodSetting::query()->firstOrNew(['code' => $code]);
                $this->fillMissing($setting, $definition)->save();
            }

            foreach (self::PAYMENT_METHODS as $code => $definition) {
                $setting = PaymentMethodSetting::query()->firstOrNew(['code' => $code]);
                $this->fillMissing($setting, $definition)->save();
            }
        });
    }
}
