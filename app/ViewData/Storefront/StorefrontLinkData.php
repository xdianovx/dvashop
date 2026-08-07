<?php

namespace App\ViewData\Storefront;

final readonly class StorefrontLinkData
{
    public function __construct(
        public string $title,
        public string $url,
        public bool $openInNewTab = false,
    ) {}
}
