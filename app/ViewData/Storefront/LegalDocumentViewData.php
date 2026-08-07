<?php

namespace App\ViewData\Storefront;

use App\Enums\LegalDocumentCode;
use App\Services\Seo\SeoData;

final readonly class LegalDocumentViewData
{
    /**
     * @param  list<list<string>>  $paragraphs
     * @param  list<array{label:string,value:string}>  $requisites
     */
    public function __construct(
        public LegalDocumentCode $code,
        public string $title,
        public array $paragraphs,
        public array $requisites,
        public SeoData $seo,
    ) {}
}
