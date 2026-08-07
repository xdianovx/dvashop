<?php

use App\Enums\NavigationZone;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\ViewData\Storefront\GlobalStorefrontData;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        CheckoutMethodSettingsSeeder::class,
        FaqSeeder::class,
        HomepageContentSeeder::class,
    ]);
});

function storefrontRequestQueryCount($test, string $routeName): int
{
    app()->forgetScopedInstances();
    DB::disableQueryLog();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $test->get(route($routeName))->assertOk();

    return count(DB::getQueryLog());
}

test('storefront pages stay inside explicit query budgets', function (string $routeName, int $budget): void {
    expect(storefrontRequestQueryCount($this, $routeName))->toBeLessThanOrEqual($budget);
})->with([
    'home' => ['home', 10],
    'about' => ['about', 10],
    'how' => ['how', 10],
    'payment' => ['payment', 10],
    'faq' => ['faq', 10],
    'partners' => ['partners', 10],
]);

test('faq navigation and homepage card growth does not create n plus one queries', function (): void {
    $faqBefore = storefrontRequestQueryCount($this, 'faq');
    $category = FaqCategory::query()->firstOrFail();
    FaqItem::factory()->count(20)->for($category, 'category')->create();
    $faqAfter = storefrontRequestQueryCount($this, 'faq');

    $homeBefore = storefrontRequestQueryCount($this, 'home');
    DB::table('homepage_category_cards')->update([
        'link_type' => 'route',
        'route_name' => 'catalog.index',
        'product_category_id' => null,
        'part_type_id' => null,
        'url' => null,
        'is_active' => true,
    ]);
    DB::table('site_navigation_items')->insert(array_map(fn (int $position): array => [
        'code' => 'query_navigation_'.$position,
        'zone' => NavigationZone::HeaderTop->value,
        'title' => 'Ссылка '.$position,
        'link_type' => 'route',
        'route_name' => 'about',
        'url' => null,
        'open_in_new_tab' => false,
        'is_active' => true,
        'position' => 1000 + $position,
        'created_at' => now(),
        'updated_at' => now(),
    ], range(1, 20)));
    $homeAfter = storefrontRequestQueryCount($this, 'home');

    expect($faqAfter)->toBe($faqBefore)
        ->and($homeAfter)->toBe($homeBefore);
});

test('global storefront data uses no more than three queries even when resolved repeatedly', function (): void {
    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $first = app(GlobalStorefrontData::class);
    $second = app(GlobalStorefrontData::class);

    expect($first)->toBe($second)
        ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});
