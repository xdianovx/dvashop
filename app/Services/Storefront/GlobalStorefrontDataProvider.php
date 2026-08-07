<?php

namespace App\Services\Storefront;

use App\Enums\LegalDocumentCode;
use App\Enums\NavigationZone;
use App\Models\LegalDocument;
use App\Models\ShopSetting;
use App\Models\SiteNavigationItem;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\StorefrontLinkData;

final readonly class GlobalStorefrontDataProvider
{
    public function __construct(
        private StorefrontDestinationResolver $destinations,
        private LegalDocumentRouteMap $legalRoutes,
    ) {}

    public function load(): GlobalStorefrontData
    {
        $settings = ShopSetting::query()
            ->where('singleton_key', ShopSetting::SINGLETON_KEY)
            ->first([
                'store_name',
                'phone_display',
                'phone_href',
                'phone_caption',
                'public_email',
                'work_hours',
                'legal_name',
                'inn',
                'ogrn',
                'legal_address',
                'vk_url',
                'telegram_url',
                'footer_copyright',
                'footer_disclaimer',
            ]);

        $navigation = collect(NavigationZone::cases())
            ->mapWithKeys(fn (NavigationZone $zone): array => [$zone->value => []])
            ->all();

        $items = SiteNavigationItem::query()
            ->where('is_active', true)
            ->ordered()
            ->get([
                'zone',
                'title',
                'link_type',
                'route_name',
                'url',
                'open_in_new_tab',
            ]);

        foreach ($items as $item) {
            $link = $this->destinations->resolve(
                title: (string) $item->title,
                type: $item->link_type,
                routeName: $item->route_name,
                url: $item->url,
                openInNewTab: (bool) $item->open_in_new_tab,
            );

            if (! $link instanceof StorefrontLinkData) {
                continue;
            }

            $navigation[$item->zone->value][] = $link;
        }

        if ($navigation[NavigationZone::Mobile->value] === []) {
            $fallbackMobileLinks = [
                ...$navigation[NavigationZone::HeaderMain->value],
                ...$navigation[NavigationZone::HeaderTop->value],
                ...$navigation[NavigationZone::FooterAbout->value],
                ...$navigation[NavigationZone::FooterDocuments->value],
            ];
            $seenUrls = [];

            foreach ($fallbackMobileLinks as $link) {
                if (isset($seenUrls[$link->url])) {
                    continue;
                }

                $seenUrls[$link->url] = true;
                $navigation[NavigationZone::Mobile->value][] = $link;
            }
        }

        $legalDocuments = LegalDocument::query()
            ->whereIn('code', array_map(fn ($code): string => $code->value, LegalDocumentCode::cases()))
            ->where('is_active', true)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderBy('id')
            ->get(['code', 'title'])
            ->map(function (LegalDocument $document): StorefrontLinkData {
                return new StorefrontLinkData(
                    title: $document->title,
                    url: $this->legalRoutes->url($document->code),
                );
            })
            ->values()
            ->all();

        $phoneDisplay = $this->nullable($settings?->phone_display);
        $phoneHref = $this->phoneHref($settings?->phone_href);
        $email = $this->email($settings?->public_email);
        $socials = [];

        foreach ([
            ['code' => 'vk', 'label' => 'ВКонтакте', 'url' => $settings?->vk_url],
            ['code' => 'telegram', 'label' => 'Telegram', 'url' => $settings?->telegram_url],
        ] as $social) {
            $url = $this->destinations->safeExternalUrl($social['url']);

            if ($url !== null) {
                $socials[] = ['code' => $social['code'], 'label' => $social['label'], 'url' => $url];
            }
        }

        $legalName = $this->nullable($settings?->legal_name);
        $inn = $this->nullable($settings?->inn);
        $ogrn = $this->nullable($settings?->ogrn);
        $legalAddress = $this->nullable($settings?->legal_address);
        $requisites = [];

        foreach ([
            ['label' => 'Юридическое наименование', 'value' => $legalName],
            ['label' => 'ИНН', 'value' => $inn],
            ['label' => 'ОГРН', 'value' => $ogrn],
            ['label' => 'Юридический адрес', 'value' => $legalAddress],
        ] as $requisite) {
            if ($requisite['value'] !== null) {
                $requisites[] = ['label' => $requisite['label'], 'value' => $requisite['value']];
            }
        }

        return new GlobalStorefrontData(
            storeName: $this->nullable($settings?->store_name) ?? 'AVTOPOROGI.ru',
            phoneDisplay: $phoneDisplay,
            phoneUrl: $phoneHref === null ? null : 'tel:'.$phoneHref,
            phoneCaption: $this->nullable($settings?->phone_caption),
            publicEmail: $email,
            emailUrl: $email === null ? null : 'mailto:'.$email,
            workHours: $this->nullable($settings?->work_hours),
            legalName: $legalName,
            inn: $inn,
            ogrn: $ogrn,
            legalAddress: $legalAddress,
            footerCopyright: $this->nullable($settings?->footer_copyright),
            footerDisclaimer: $this->nullable($settings?->footer_disclaimer),
            navigation: $navigation,
            legalDocuments: $legalDocuments,
            socials: $socials,
            requisites: $requisites,
        );
    }

    private function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private function phoneHref(mixed $value): ?string
    {
        $value = $this->nullable($value);

        if ($value === null || preg_match('/^\+?\d{10,15}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function email(mixed $value): ?string
    {
        $value = $this->nullable($value);

        if ($value === null || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower($value);
    }
}
