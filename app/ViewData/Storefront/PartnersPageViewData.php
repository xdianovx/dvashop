<?php

namespace App\ViewData\Storefront;

use App\Services\Seo\SeoData;

final readonly class PartnersPageViewData
{
    /**
     * @param  list<string>  $titleLines
     * @param  list<array{text:string,strong:bool}>  $subtitleSegments
     * @param  list<string>  $benefits
     * @param  list<string>  $cooperationTitleLines
     * @param  list<array{code:string,icon:string,modifier:string,title_lines:list<string>}>  $partnerTypes
     * @param  list<array{code:string,segments:list<array{text:string,strong:bool}>}>  $facts
     */
    public function __construct(
        public array $titleLines,
        public array $subtitleSegments,
        public array $benefits,
        public array $cooperationTitleLines,
        public array $partnerTypes,
        public ?string $aboutTitle,
        public array $facts,
        public SeoData $seo,
    ) {}
}
