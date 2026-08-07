<?php

namespace App\Services\Storefront;

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\LegalDocumentViewData;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LegalDocumentViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private LegalDocumentRouteMap $routes,
        private StorefrontSeoFactory $seo,
    ) {}

    public function load(LegalDocumentCode $code): LegalDocumentViewData
    {
        $document = LegalDocument::query()
            ->where('code', $code->value)
            ->where('is_active', true)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->first(['title', 'body']);

        if (! $document instanceof LegalDocument || blank($document->body)) {
            throw new NotFoundHttpException;
        }

        $paragraphs = preg_split('/\R{2,}/u', trim((string) $document->body)) ?: [];
        $paragraphs = collect($paragraphs)
            ->map(function (string $paragraph): array {
                $lines = preg_split('/\R/u', trim($paragraph)) ?: [];

                return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
            })
            ->filter(fn (array $lines): bool => $lines !== [])
            ->values()
            ->all();

        return new LegalDocumentViewData(
            code: $code,
            title: (string) $document->title,
            paragraphs: $paragraphs,
            requisites: $this->global->requisites,
            seo: $this->seo->page(
                pageTitle: (string) $document->title,
                description: trim((string) $document->body),
                canonical: $this->routes->url($code),
                storeName: $this->global->storeName,
            ),
        );
    }
}
