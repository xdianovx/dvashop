<?php

namespace App\Services\Storefront;

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageStoryMediaType;
use App\Enums\NavigationLinkType;
use App\Models\HomepageMetric;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\VehicleMake;
use App\Services\Media\MediaUrlService;
use App\Services\PublicCatalogCache;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\HomepageViewData;
use Illuminate\Support\Facades\DB;

final readonly class HomepageViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private StorefrontSeoFactory $seo,
        private StorefrontTextPresenter $text,
        private PublicCatalogCache $catalogCache,
        private MediaUrlService $mediaUrls,
    ) {}

    public function load(): HomepageViewData
    {
        $sections = HomepageSection::query()
            ->active()
            ->ordered()
            ->get(['code', 'title'])
            ->mapWithKeys(fn (HomepageSection $section): array => [
                $section->code->value => ['title' => $this->text->plain($section->title)],
            ])
            ->all();

        $stories = HomepageStoryGroup::query()
            ->active()
            ->ordered()
            ->with(['items' => fn ($query) => $query->active()->ordered()])
            ->get(['id', 'title', 'cover_image_path'])
            ->map(function (HomepageStoryGroup $group): ?array {
                $coverUrl = $this->storyMediaUrl($group->cover_image_path);
                if ($coverUrl === null) {
                    return null;
                }

                $items = $group->items->map(function (HomepageStoryItem $item): ?array {
                    $mediaUrl = $this->storyMediaUrl($item->media_path);
                    if ($mediaUrl === null) {
                        return null;
                    }

                    $ctaUrl = $this->safeStoryCtaUrl($item->cta_url);

                    return [
                        'type' => $item->media_type->value,
                        'media_url' => $mediaUrl,
                        'alt' => $this->text->plain($item->alt_text) ?? '',
                        'duration_ms' => $item->media_type === HomepageStoryMediaType::Image
                            ? max(3, min(60, (int) ($item->duration_seconds ?? 10))) * 1000
                            : null,
                        'cta_label' => $ctaUrl === null ? null : ($this->text->plain($item->cta_label) ?? 'Посмотреть'),
                        'cta_url' => $ctaUrl,
                        'open_in_new_tab' => $ctaUrl !== null && (bool) $item->open_in_new_tab,
                    ];
                })->filter()->values()->all();

                if ($items === []) {
                    return null;
                }

                return [
                    'title' => (string) $group->title,
                    'cover_url' => $coverUrl,
                    'items' => $items,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $categoryCards = DB::table('homepage_category_cards as cards')
            ->leftJoin('product_categories as categories', 'categories.id', '=', 'cards.product_category_id')
            ->leftJoin('part_types as part_types', 'part_types.id', '=', 'cards.part_type_id')
            ->where('cards.is_active', true)
            ->orderBy('cards.position')
            ->orderBy('cards.id')
            ->get([
                'cards.code',
                'cards.title',
                'cards.link_type',
                'cards.route_name',
                'cards.product_category_id',
                'cards.part_type_id',
                'categories.full_slug as category_slug',
                'categories.is_active as category_active',
                'categories.deleted_at as category_deleted_at',
                'part_types.full_slug as part_type_slug',
                'part_types.is_active as part_type_active',
                'part_types.deleted_at as part_type_deleted_at',
            ])
            ->map(function (object $card): ?array {
                $code = HomepageCategoryCardCode::tryFrom((string) $card->code);
                $title = $this->text->plain((string) $card->title);

                if (! $code instanceof HomepageCategoryCardCode || $title === null) {
                    return null;
                }

                $hasCategory = $card->product_category_id !== null;
                $hasPartType = $card->part_type_id !== null;

                if ($hasCategory && $hasPartType) {
                    return null;
                }

                if ($hasCategory) {
                    if (! (bool) $card->category_active
                        || $card->category_deleted_at !== null
                        || blank($card->category_slug)) {
                        return null;
                    }

                    $url = route('catalog.index', ['category' => $card->category_slug]);
                } elseif ($hasPartType) {
                    if (! (bool) $card->part_type_active
                        || $card->part_type_deleted_at !== null
                        || blank($card->part_type_slug)) {
                        return null;
                    }

                    $url = route('catalog.index', ['part_type' => $card->part_type_slug]);
                } elseif ($card->link_type === NavigationLinkType::Route->value
                    && $card->route_name === 'catalog.index') {
                    $url = route('catalog.index');
                } else {
                    return null;
                }

                $visual = $this->categoryVisual($code);

                return [
                    'code' => $code->value,
                    'title' => $title,
                    'title_lines' => $code === HomepageCategoryCardCode::Commercial
                        ? $this->text->lines($title, 'транспорт')
                        : [$title],
                    'url' => $url,
                    'modifier' => $visual['modifier'],
                    'layers' => $visual['layers'],
                ];
            })
            ->filter()
            ->values()
            ->all();

        $metrics = HomepageMetric::query()
            ->active()
            ->ordered()
            ->get(['code', 'prefix', 'value', 'suffix', 'text'])
            ->map(fn (HomepageMetric $metric): array => [
                'code' => $metric->code->value,
                'prefix' => $this->text->plain($metric->prefix),
                'value' => (string) $metric->value,
                'suffix' => $this->text->plain($metric->suffix),
                'text' => (string) $metric->text,
                'icon' => $this->metricIcon($metric->code),
            ])
            ->values()
            ->all();

        $vehicleMakes = $this->catalogCache->activeMakes()
            ->map(fn (VehicleMake $make): array => [
                'title' => (string) $make->title,
                'slug' => (string) $make->slug,
            ])
            ->values()
            ->all();

        return new HomepageViewData(
            sections: $sections,
            stories: $stories,
            categoryCards: $categoryCards,
            metrics: $metrics,
            vehicleMakes: $vehicleMakes,
            seo: $this->seo->home($this->global->storeName),
        );
    }

    private function storyMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || ! str_starts_with($path, 'uploads/homepage/stories/')
            || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }

        return $this->mediaUrls->publicDiskUrl($path);
    }

    private function safeStoryCtaUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//') || str_contains($url, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $url) === 1) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true) || blank(parse_url($url, PHP_URL_HOST))) {
                return null;
            }
        }

        return $url;
    }

    /** @return array{modifier:string,layers:list<array{src:string,class:string}>} */
    private function categoryVisual(HomepageCategoryCardCode $code): array
    {
        return match ($code) {
            HomepageCategoryCardCode::Sills => [
                'modifier' => 'sills',
                'layers' => [
                    ['src' => '/img/categories/sills-blend.png', 'class' => 'categories__layer categories__layer--1 categories__layer--blend'],
                    ['src' => '/img/categories/sills.png', 'class' => 'categories__layer categories__layer--2'],
                ],
            ],
            HomepageCategoryCardCode::Commercial => [
                'modifier' => 'commercial',
                'layers' => [
                    ['src' => '/img/categories/commercial-blend.png', 'class' => 'categories__layer categories__layer--1 categories__layer--blend'],
                    ['src' => '/img/categories/commercial.png', 'class' => 'categories__layer categories__layer--2'],
                ],
            ],
            HomepageCategoryCardCode::BodyRepair => [
                'modifier' => 'repair',
                'layers' => [
                    ['src' => '/img/categories/repair-blend.png', 'class' => 'categories__layer categories__layer--1 categories__layer--blend'],
                    ['src' => '/img/categories/repair.png', 'class' => 'categories__layer categories__layer--2'],
                ],
            ],
            HomepageCategoryCardCode::FrontArches => [
                'modifier' => 'front',
                'layers' => [
                    ['src' => '/img/categories/front-arches-blend.png', 'class' => 'categories__layer categories__layer--1 categories__layer--blend'],
                    ['src' => '/img/categories/front-arches.png', 'class' => 'categories__layer categories__layer--2'],
                    ['src' => '/img/categories/front-arches-2.png', 'class' => 'categories__layer categories__layer--3'],
                ],
            ],
            HomepageCategoryCardCode::RearArches => [
                'modifier' => 'rear',
                'layers' => [
                    ['src' => '/img/categories/rear-arches-blend.png', 'class' => 'categories__layer categories__layer--1 categories__layer--blend'],
                    ['src' => '/img/categories/rear-arches.png', 'class' => 'categories__layer categories__layer--2'],
                ],
            ],
        };
    }

    private function metricIcon(HomepageMetricCode $code): string
    {
        return match ($code) {
            HomepageMetricCode::SinceYear => '/img/about/1.svg',
            HomepageMetricCode::VehicleDatabase => '/img/about/2.svg',
            HomepageMetricCode::ItemsSold => '/img/about/3.svg',
            HomepageMetricCode::OriginalFit => '/img/about/4.svg',
            HomepageMetricCode::PriceAdvantage => '/img/about/5.svg',
        };
    }
}
