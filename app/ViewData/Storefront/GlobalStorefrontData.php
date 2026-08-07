<?php

namespace App\ViewData\Storefront;

use App\Enums\NavigationZone;

final readonly class GlobalStorefrontData
{
    /**
     * @param  array<string, list<StorefrontLinkData>>  $navigation
     * @param  list<StorefrontLinkData>  $legalDocuments
     * @param  list<array{code:string,label:string,url:string}>  $socials
     * @param  list<array{label:string,value:string}>  $requisites
     */
    public function __construct(
        public string $storeName,
        public ?string $phoneDisplay,
        public ?string $phoneUrl,
        public ?string $phoneCaption,
        public ?string $publicEmail,
        public ?string $emailUrl,
        public ?string $workHours,
        public ?string $legalName,
        public ?string $inn,
        public ?string $ogrn,
        public ?string $legalAddress,
        public ?string $footerCopyright,
        public ?string $footerDisclaimer,
        public array $navigation,
        public array $legalDocuments,
        public array $socials,
        public array $requisites,
    ) {}

    /** @return list<StorefrontLinkData> */
    public function navigationFor(NavigationZone $zone): array
    {
        return $this->navigation[$zone->value] ?? [];
    }
}
