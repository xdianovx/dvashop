<?php

namespace App\Services\Seo;

final readonly class SeoData
{
    public function __construct(
        public string $title,
        public ?string $description,
        public string $canonical,
        public ?string $h1 = null,
        public ?string $seoText = null,
        public bool $noindex = false,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?string $ogImage = null,
    ) {}

    public static function technicalPage(string $title, string $canonical): self
    {
        return new self(
            title: $title,
            description: null,
            canonical: $canonical,
            noindex: true,
        );
    }

    /**
     * @return array{pageTitle: string, metaTitle: string, metaDescription: string|null, canonicalUrl: string, seoH1: string|null, seoText: string|null, noindex: bool, ogTitle: string|null, ogDescription: string|null, ogImage: string|null}
     */
    public function toViewData(): array
    {
        return [
            'pageTitle' => $this->title,
            'metaTitle' => $this->title,
            'metaDescription' => $this->description,
            'canonicalUrl' => $this->canonical,
            'seoH1' => $this->h1,
            'seoText' => $this->seoText,
            'noindex' => $this->noindex,
            'ogTitle' => $this->ogTitle,
            'ogDescription' => $this->ogDescription,
            'ogImage' => $this->ogImage,
        ];
    }
}
