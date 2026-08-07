<?php

namespace App\Services\Storefront;

final readonly class StaticPageSnapshot
{
    /**
     * @param  array<string, array{label:?string,title:?string,subtitle:?string,body:?string,items:array<string,array{label:?string,title:?string,text:?string}>}>  $sections
     */
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public array $sections,
    ) {}
}
