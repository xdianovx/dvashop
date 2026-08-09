<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageQuickLinkCode;
use App\Models\ProductVariant;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('storefront keeps the approved key classes assets and homepage block order', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        CheckoutMethodSettingsSeeder::class,
        FaqSeeder::class,
        HomepageContentSeeder::class,
    ]);

    DB::table('homepage_quick_links')->where('code', HomepageQuickLinkCode::Socials->value)->update([
        'link_type' => 'route', 'route_name' => 'about', 'url' => null, 'open_in_new_tab' => false, 'is_active' => true,
    ]);
    DB::table('homepage_category_cards')->where('code', HomepageCategoryCardCode::BodyRepair->value)->update([
        'link_type' => 'route', 'route_name' => 'catalog.index', 'product_category_id' => null,
        'part_type_id' => null, 'url' => null, 'open_in_new_tab' => false, 'is_active' => true,
    ]);

    $home = $this->get(route('home'))->assertOk()
        ->assertSee('hero-circles-section', false)
        ->assertSee('class="search"', false)
        ->assertSee('search__form', false)
        ->assertSee('class="categories"', false)
        ->assertSee('class="about"', false)
        ->assertDontSee('class="faq"', false);

    $html = $home->getContent();
    $quickLinksPosition = strpos($html, 'hero-circles-section');
    $searchPosition = strpos($html, 'class="search"');
    $categoriesPosition = strpos($html, 'class="categories"');
    $aboutPosition = strpos($html, 'class="about"');
    expect($quickLinksPosition)->toBeInt()
        ->and($searchPosition)->toBeInt()
        ->and($categoriesPosition)->toBeInt()
        ->and($aboutPosition)->toBeInt()
        ->and($quickLinksPosition)->toBeLessThan($searchPosition)
        ->and($searchPosition)->toBeLessThan($categoriesPosition)
        ->and($categoriesPosition)->toBeLessThan($aboutPosition);

    foreach ([
        'about' => ['about-page', 'about-hero', 'about-metrics', 'about-tech', 'about-goal'],
        'how' => ['how-page', 'how-page__grid', '/img/how/step-1.svg'],
        'payment' => ['payment-page', 'payment-page__grid', '/img/payment/cash.svg'],
        'faq' => ['faq-page', 'faq-page__tabs', 'data-faq-toggle'],
        'partners' => ['partners-page', 'partners-page__benefits', 'partners-page__coop', '/img/partners/team.jpg'],
    ] as $routeName => $needles) {
        $response = $this->get(route($routeName))->assertOk();
        foreach ($needles as $needle) {
            $response->assertSee($needle, false);
        }
    }
});

test('product page does not render the legacy placeholder faq block', function (): void {
    $this->seed(ShopSettingsSeeder::class);
    $variant = ProductVariant::factory()->create();

    $this->get(route('products.show', $variant->product->slug))
        ->assertOk()
        ->assertDontSee('class="faq"', false)
        ->assertDontSee('data-faq-toggle', false);
});
