<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageSectionCode;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\ProductVariant;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('storefront keeps the approved key classes assets and homepage block order', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        CheckoutMethodSettingsSeeder::class,
        FaqSeeder::class,
        HomepageContentSeeder::class,
    ]);

    Storage::fake('public');
    Storage::disk('public')->put('uploads/homepage/stories/cover.jpg', 'cover');
    Storage::disk('public')->put('uploads/homepage/stories/story.jpg', 'story');
    $group = HomepageStoryGroup::factory()->create(['cover_image_path' => 'uploads/homepage/stories/cover.jpg']);
    HomepageStoryItem::factory()->for($group, 'group')->create(['media_path' => 'uploads/homepage/stories/story.jpg']);
    DB::table('homepage_category_cards')->where('code', HomepageCategoryCardCode::BodyRepair->value)->update([
        'link_type' => 'route', 'route_name' => 'catalog.index', 'product_category_id' => null,
        'part_type_id' => null, 'url' => null, 'open_in_new_tab' => false, 'is_active' => true,
    ]);

    $home = $this->get(route('home'))->assertOk()
        ->assertSee('hero-circles-section', false)
        ->assertSee('class="search"', false)
        ->assertSee('search__form', false)
        ->assertSee('class="categories"', false)
        ->assertSee('class="homepage-reviews section"', false)
        ->assertSee('<review-lab data-widgetid="69984c4658896b169079008c"></review-lab>', false)
        ->assertSee('class="about"', false)
        ->assertDontSee('class="faq"', false);

    $html = $home->getContent();
    $quickLinksPosition = strpos($html, 'hero-circles-section');
    $searchPosition = strpos($html, 'class="search"');
    $categoriesPosition = strpos($html, 'class="categories"');
    $reviewsPosition = strpos($html, 'class="homepage-reviews section"');
    $aboutPosition = strpos($html, 'class="about"');
    expect($quickLinksPosition)->toBeInt()
        ->and($searchPosition)->toBeInt()
        ->and($categoriesPosition)->toBeInt()
        ->and($reviewsPosition)->toBeInt()
        ->and($aboutPosition)->toBeInt()
        ->and($quickLinksPosition)->toBeLessThan($searchPosition)
        ->and($searchPosition)->toBeLessThan($categoriesPosition)
        ->and($categoriesPosition)->toBeLessThan($reviewsPosition)
        ->and($reviewsPosition)->toBeLessThan($aboutPosition)
        ->and(substr_count($html, 'https://app.reviewlab.ru/widget/index-es2015.js'))->toBe(1);

    HomepageSection::query()->where('code', HomepageSectionCode::Reviews)->update(['is_active' => false]);
    $withoutReviews = $this->get(route('home'))->assertOk();
    expect($withoutReviews->getContent())->not->toContain('<review-lab')
        ->not->toContain('https://app.reviewlab.ru/widget/index-es2015.js');

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
