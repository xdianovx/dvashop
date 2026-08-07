<?php

namespace App\ViewData\Storefront;

use App\Services\Seo\SeoData;

final readonly class FaqPageViewData
{
    /**
     * @param  list<array{title:string,items:list<array{question:string,answer:string}>}>  $categories
     */
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public array $categories,
        public SeoData $seo,
    ) {}
}
