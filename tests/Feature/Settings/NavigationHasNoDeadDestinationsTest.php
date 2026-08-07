<?php

use App\Enums\NavigationLinkType;
use App\Models\SiteNavigationItem;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('seeded active navigation has only existing safe destinations', function (): void {
    $this->seed(ShopSettingsSeeder::class);
    $items = SiteNavigationItem::query()->get();

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        if (! $item->is_active) {
            continue;
        }

        expect($item->link_type)->not->toBeNull()
            ->and($item->route_name === '#')->toBeFalse()
            ->and($item->url === '#')->toBeFalse();

        if ($item->link_type === NavigationLinkType::Route) {
            expect($item->route_name)->toBeIn([
                'home', 'catalog.index', 'about', 'how', 'payment', 'faq', 'partners', 'cart.show',
            ])->and(Route::has((string) $item->route_name))->toBeTrue()
                ->and($item->url)->toBeNull();
        } else {
            $scheme = mb_strtolower((string) parse_url((string) $item->url, PHP_URL_SCHEME));
            expect(filter_var($item->url, FILTER_VALIDATE_URL))->not->toBeFalse()
                ->and($scheme)->toBeIn(['http', 'https'])
                ->and(parse_url((string) $item->url, PHP_URL_HOST))->not->toBeEmpty()
                ->and($item->route_name)->toBeNull();
        }
    }

    expect($items->pluck('code')->all())->not->toContain(
        'reviews', 'favorites', 'subscription', 'service_search', 'promotions', 'callback', 'account',
    );
});
