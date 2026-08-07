<?php

namespace App\ViewData\Storefront;

use App\Services\Seo\SeoData;

final readonly class PaymentPageViewData
{
    /**
     * @param  list<array{code:string,kind:string,icon:string,title_lines:list<string>,description:?string}>  $methods
     */
    public function __construct(
        public string $title,
        public array $methods,
        public SeoData $seo,
    ) {}
}
