<?php

namespace App\ViewData\Storefront;

use App\Enums\LegalDocumentCode;
use App\Services\Seo\SeoData;
use Illuminate\Contracts\Support\Htmlable;

final readonly class LegalDocumentViewData
{
    /**
     * @param  list<array{label:string,value:string}>  $requisites
     */
    public function __construct(
        public LegalDocumentCode $code,
        public string $title,
        public Htmlable $body,
        public array $requisites,
        public SeoData $seo,
    ) {}
}
