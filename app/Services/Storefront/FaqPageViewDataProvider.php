<?php

namespace App\Services\Storefront;

use App\Enums\StaticPageCode;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\ViewData\Storefront\FaqPageViewData;
use App\ViewData\Storefront\GlobalStorefrontData;

final readonly class FaqPageViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private StaticPageContentReader $pages,
        private StorefrontSeoFactory $seo,
        private StorefrontTextPresenter $text,
    ) {}

    public function load(): FaqPageViewData
    {
        $snapshot = $this->pages->read(StaticPageCode::Faq);
        $categories = FaqCategory::query()
            ->active()
            ->ordered()
            ->with(['items' => fn ($query) => $query
                ->active()
                ->ordered()
                ->select(['id', 'faq_category_id', 'question', 'answer'])])
            ->get(['id', 'title'])
            ->map(function (FaqCategory $category): ?array {
                $items = $category->items
                    ->map(fn (FaqItem $item): array => [
                        'question' => (string) $item->question,
                        'answer' => (string) $item->answer,
                    ])
                    ->values()
                    ->all();

                if ($items === []) {
                    return null;
                }

                return [
                    'title' => (string) $category->title,
                    'items' => $items,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $title = $this->text->plain($snapshot?->title) ?? 'Вопросы и ответы';
        $subtitle = $this->text->plain($snapshot?->subtitle);

        return new FaqPageViewData(
            title: $title,
            subtitle: $subtitle,
            categories: $categories,
            seo: $this->seo->page(
                pageTitle: $title,
                description: $subtitle ?? ($categories[0]['items'][0]['answer'] ?? null),
                canonical: route('faq'),
                storeName: $this->global->storeName,
            ),
        );
    }
}
