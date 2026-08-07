<?php

use App\Enums\DeliveryMethod;
use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageQuickLinkCode;
use App\Enums\HomepageSectionCode;
use App\Enums\LegalDocumentCode;
use App\Enums\PaymentMethod;
use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\DeliveryMethodSetting;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageQuickLink;
use App\Models\HomepageSection;
use App\Models\LegalDocument;
use App\Models\PaymentMethodSetting;
use App\Models\ShopSetting;
use App\Models\SiteNavigationItem;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;

test('editable frontend content inventory is explicit fixed and contains no universal page builder', function (): void {
    $models = [
        ShopSetting::class,
        SiteNavigationItem::class,
        HomepageSection::class,
        HomepageQuickLink::class,
        HomepageCategoryCard::class,
        HomepageMetric::class,
        StaticPage::class,
        StaticPageSection::class,
        StaticPageItem::class,
        FaqCategory::class,
        FaqItem::class,
        PaymentMethodSetting::class,
        DeliveryMethodSetting::class,
        LegalDocument::class,
    ];

    foreach ($models as $model) {
        expect(class_exists($model), $model)->toBeTrue();
    }

    expect(array_column(HomepageSectionCode::cases(), 'value'))->toBe([
        'quick_links', 'vehicle_search', 'category_cards', 'about_metrics',
    ])->and(array_column(HomepageQuickLinkCode::cases(), 'value'))->toBe([
        'new_arrivals', 'promotions', 'service_search', 'reviews', 'socials', 'galvanized', 'fitting',
    ])->and(array_column(HomepageCategoryCardCode::cases(), 'value'))->toBe([
        'sills', 'commercial', 'body_repair', 'front_arches', 'rear_arches',
    ])->and(array_column(HomepageMetricCode::cases(), 'value'))->toBe([
        'since_year', 'vehicle_database', 'items_sold', 'original_fit', 'price_advantage',
    ])->and(array_column(StaticPageCode::cases(), 'value'))->toBe([
        'about', 'how', 'payment', 'faq', 'partners',
    ])->and(StaticPageSectionCode::cases())->toHaveCount(8)
        ->and(StaticPageItemCode::cases())->toHaveCount(24)
        ->and(array_column(PaymentMethod::cases(), 'value'))->toBe([
            'card', 'sbp', 'invoice', 'cash_on_delivery',
        ])->and(array_column(DeliveryMethod::cases(), 'value'))->toBe([
            'pickup', 'courier', 'transport_company', 'post',
        ])->and(array_column(LegalDocumentCode::cases(), 'value'))->toBe([
            'privacy_policy', 'sale_rules', 'returns_exchange', 'information_usage_rules',
        ]);

    $migrationSource = collect(glob(database_path('migrations/*.php')) ?: [])
        ->map(fn (string $path): string => (string) file_get_contents($path))
        ->implode("\n");

    expect($migrationSource)->not->toContain("Schema::create('content_blocks'")
        ->not->toContain("Schema::create('page_blocks'")
        ->not->toContain("Schema::create('widgets'")
        ->not->toContain("Schema::create('page_widgets'")
        ->and(class_exists('App\\Models\\ContentBlock'))->toBeFalse()
        ->and(class_exists('App\\Models\\PageBlock'))->toBeFalse()
        ->and(class_exists('App\\Models\\Widget'))->toBeFalse();
});
