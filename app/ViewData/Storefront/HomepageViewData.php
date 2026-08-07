<?php

namespace App\ViewData\Storefront;

use App\Services\Seo\SeoData;

final readonly class HomepageViewData
{
    /**
     * @param  array<string, array{title:?string}>  $sections
     * @param  list<array{code:string,title:string,url:string,open_in_new_tab:bool,image:string}>  $quickLinks
     * @param  list<array{code:string,title:string,title_lines:list<string>,url:string,modifier:string,layers:list<array{src:string,class:string}>}>  $categoryCards
     * @param  list<array{code:string,prefix:?string,value:string,suffix:?string,text:string,icon:string}>  $metrics
     */
    public function __construct(
        public array $sections,
        public array $quickLinks,
        public array $categoryCards,
        public array $metrics,
        public SeoData $seo,
    ) {}

    public function hasSection(string $code): bool
    {
        return array_key_exists($code, $this->sections);
    }

    public function sectionTitle(string $code): ?string
    {
        return $this->sections[$code]['title'] ?? null;
    }
}
