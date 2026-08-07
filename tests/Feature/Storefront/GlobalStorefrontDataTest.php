<?php

use App\Enums\NavigationZone;
use App\ViewData\Storefront\GlobalStorefrontData;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('global storefront data is safe immutable and loaded once per request scope', function (): void {
    $this->seed(ShopSettingsSeeder::class);

    DB::table('site_navigation_items')->insert([
        'code' => 'unsafe_test_route',
        'zone' => NavigationZone::HeaderTop->value,
        'title' => 'Небезопасная ссылка',
        'link_type' => 'route',
        'route_name' => 'missing.route',
        'url' => null,
        'open_in_new_tab' => false,
        'is_active' => true,
        'position' => 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $first = app(GlobalStorefrontData::class);
    $second = app(GlobalStorefrontData::class);
    $queries = DB::getQueryLog();

    expect($first)->toBe($second)
        ->and(count($queries))->toBeLessThanOrEqual(3)
        ->and($first->phoneDisplay)->toBe('8 800 100 56 25')
        ->and($first->phoneUrl)->toBe('tel:+78001005625')
        ->and($first->publicEmail)->toBeNull()
        ->and(collect($first->navigationFor(NavigationZone::HeaderTop))->pluck('url')->implode(' '))->not->toContain('missing.route');
});
