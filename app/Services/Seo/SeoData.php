<?php

namespace App\Services\Seo;

final readonly class SeoData
{
    public function __construct(
        public string $title,
        public ?string $description,
        public string $canonical,
    ) {}

    /**
     * @return array{pageTitle: string, metaTitle: string, metaDescription: string|null, canonicalUrl: string}
     */
    public function toViewData(): array
    {
        return [
            'pageTitle' => $this->title,
            'metaTitle' => $this->title,
            'metaDescription' => $this->description,
            'canonicalUrl' => $this->canonical,
        ];
    }
}
