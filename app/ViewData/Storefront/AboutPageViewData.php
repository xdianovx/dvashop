<?php

namespace App\ViewData\Storefront;

use App\Services\Seo\SeoData;

final readonly class AboutPageViewData
{
    /**
     * @param  array{badge:?string,title_lines:list<string>,lead_prefix:?string,lead_text:?string}|null  $hero
     * @param  list<array{title:string,text:?string,icon:string}>  $metrics
     * @param  array{title:?string,subtitle:?string,items:list<array{number:string,segments:list<array{text:string,strong:bool}>}>}|null  $technologies
     * @param  array{label:?string,body:?string}|null  $goal
     */
    public function __construct(
        public string $title,
        public ?array $hero,
        public array $metrics,
        public ?array $technologies,
        public ?array $goal,
        public SeoData $seo,
    ) {}
}
