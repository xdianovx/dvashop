<?php

namespace App\ViewData\Storefront;

use App\Services\Seo\SeoData;

final readonly class HowPageViewData
{
    /**
     * @param  list<array{code:string,number:string,icon:string,title_lines:list<string>,text:string,segments:list<array{text:string,strong:bool,break_after:bool}>,show_phone:bool}>  $steps
     */
    public function __construct(
        public string $title,
        public array $steps,
        public SeoData $seo,
    ) {}
}
