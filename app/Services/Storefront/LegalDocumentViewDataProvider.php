<?php

namespace App\Services\Storefront;

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use App\Services\Legal\LegalRichContentSanitizer;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\LegalDocumentViewData;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LegalDocumentViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private LegalDocumentRouteMap $routes,
        private StorefrontSeoFactory $seo,
        private LegalRichContentSanitizer $richContent,
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

        return new LegalDocumentViewData(
            code: $code,
            title: (string) $document->title,
            body: $this->richContent->render($document->body),
            requisites: $this->global->requisites,
            seo: $this->seo->page(
                pageTitle: (string) $document->title,
                description: $this->richContent->plainText($document->body),
                canonical: $this->routes->url($code),
                storeName: $this->global->storeName,
            ),
        );
    }
}
