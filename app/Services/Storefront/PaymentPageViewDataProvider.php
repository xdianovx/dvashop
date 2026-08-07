<?php

namespace App\Services\Storefront;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Enums\StaticPageCode;
use App\Models\DeliveryMethodSetting;
use App\Models\PaymentMethodSetting;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\PaymentPageViewData;
use Illuminate\Support\Collection;

final readonly class PaymentPageViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private StaticPageContentReader $pages,
        private StorefrontSeoFactory $seo,
        private StorefrontTextPresenter $text,
    ) {}

    public function load(): PaymentPageViewData
    {
        $snapshot = $this->pages->read(StaticPageCode::Payment);

        $paymentSettings = PaymentMethodSetting::query()
            ->active()
            ->whereIn('code', [
                PaymentMethod::CashOnDelivery->value,
                PaymentMethod::Invoice->value,
            ])
            ->get(['code', 'page_title', 'page_description'])
            ->keyBy(fn (PaymentMethodSetting $setting): string => $setting->code->value);

        $deliverySettings = DeliveryMethodSetting::query()
            ->active()
            ->where('code', DeliveryMethod::TransportCompany->value)
            ->get(['code', 'page_title', 'page_description'])
            ->keyBy(fn (DeliveryMethodSetting $setting): string => $setting->code->value);

        $methods = array_values(array_filter([
            $this->paymentCard($paymentSettings, PaymentMethod::CashOnDelivery),
            $this->paymentCard($paymentSettings, PaymentMethod::Invoice),
            $this->deliveryCard($deliverySettings, DeliveryMethod::TransportCompany),
        ]));

        $title = $this->text->plain($snapshot?->title) ?? 'Оплата и доставка';

        return new PaymentPageViewData(
            title: $title,
            methods: $methods,
            seo: $this->seo->page(
                pageTitle: $title,
                description: $methods[0]['description'] ?? $snapshot?->subtitle,
                canonical: route('payment'),
                storeName: $this->global->storeName,
            ),
        );
    }

    /**
     * @param  Collection<string, PaymentMethodSetting>  $settings
     * @return array{code:string,kind:string,icon:string,title_lines:list<string>,description:?string}|null
     */
    private function paymentCard(Collection $settings, PaymentMethod $code): ?array
    {
        $setting = $settings->get($code->value);

        if (! $setting instanceof PaymentMethodSetting) {
            return null;
        }

        $title = $this->text->plain($setting->page_title);
        $description = $this->text->plain($setting->page_description);

        if ($title === null && $description === null) {
            return null;
        }

        return [
            'code' => $code->value,
            'kind' => 'payment',
            'icon' => $this->paymentIcon($code),
            'title_lines' => $this->paymentTitleLines($code, $title),
            'description' => $description,
        ];
    }

    /**
     * @param  Collection<string, DeliveryMethodSetting>  $settings
     * @return array{code:string,kind:string,icon:string,title_lines:list<string>,description:?string}|null
     */
    private function deliveryCard(Collection $settings, DeliveryMethod $code): ?array
    {
        $setting = $settings->get($code->value);

        if (! $setting instanceof DeliveryMethodSetting) {
            return null;
        }

        $title = $this->text->plain($setting->page_title);
        $description = $this->text->plain($setting->page_description);

        if ($title === null && $description === null) {
            return null;
        }

        return [
            'code' => $code->value,
            'kind' => 'delivery',
            'icon' => $this->deliveryIcon($code),
            'title_lines' => $this->deliveryTitleLines($title),
            'description' => $description,
        ];
    }

    private function paymentIcon(PaymentMethod $code): string
    {
        return match ($code) {
            PaymentMethod::Invoice => '/img/payment/invoice.svg',
            PaymentMethod::CashOnDelivery, PaymentMethod::Card, PaymentMethod::Sbp => '/img/payment/cash.svg',
        };
    }

    /** @return list<string> */
    private function paymentTitleLines(PaymentMethod $code, ?string $title): array
    {
        return match ($code) {
            PaymentMethod::CashOnDelivery => $this->text->lines($title, 'или оплата картой'),
            PaymentMethod::Invoice => $this->text->lines($title, 'для юридических лиц'),
            default => $this->text->lines($title),
        };
    }

    private function deliveryIcon(DeliveryMethod $code): string
    {
        return match ($code) {
            DeliveryMethod::TransportCompany,
            DeliveryMethod::Pickup,
            DeliveryMethod::Courier,
            DeliveryMethod::Post => '/img/payment/delivery.svg',
        };
    }

    /** @return list<string> */
    private function deliveryTitleLines(?string $title): array
    {
        return $this->text->lines($title);
    }
}
