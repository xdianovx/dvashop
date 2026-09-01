<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageSectionCode;
use App\Enums\LegalDocumentCode;
use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\DeliveryMethodSetting;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageSection;
use App\Models\LegalDocument;
use App\Models\PaymentMethodSetting;
use App\Models\ShopSetting;
use App\Models\SiteNavigationItem;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\LegalDocumentsSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeded storefront system inventory contains every fixed required record', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
        HomepageContentSeeder::class,
        CheckoutMethodSettingsSeeder::class,
        LegalDocumentsSeeder::class,
    ]);

    expect(StaticPage::query()->toBase()->pluck('code')->all())->toEqualCanonicalizing(array_column(StaticPageCode::cases(), 'value'))
        ->and(StaticPageSection::query()->toBase()->pluck('code')->all())->toEqualCanonicalizing(array_column(StaticPageSectionCode::cases(), 'value'))
        ->and(StaticPageItem::query()->toBase()->pluck('code')->all())->toEqualCanonicalizing(array_column(StaticPageItemCode::cases(), 'value'))
        ->and(HomepageSection::query()->toBase()->pluck('code')->all())->toEqualCanonicalizing(array_column(HomepageSectionCode::cases(), 'value'))
        ->and(HomepageCategoryCard::query()->toBase()->pluck('code')->all())->toEqualCanonicalizing(array_column(HomepageCategoryCardCode::cases(), 'value'))
        ->and(HomepageMetric::query()->toBase()->pluck('code')->all())->toEqualCanonicalizing(array_column(HomepageMetricCode::cases(), 'value'))
        ->and(LegalDocument::query()->toBase()->pluck('code')->all())->toEqualCanonicalizing(array_column(LegalDocumentCode::cases(), 'value'))
        ->and(ShopSetting::query()->count())->toBe(1)
        ->and(SiteNavigationItem::query()->count())->toBeGreaterThan(0)
        ->and(FaqCategory::query()->count())->toBeGreaterThan(0)
        ->and(FaqItem::query()->count())->toBeGreaterThan(0)
        ->and(PaymentMethodSetting::query()->count())->toBe(4)
        ->and(DeliveryMethodSetting::query()->count())->toBe(4);
});
