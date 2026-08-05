<?php

namespace Database\Seeders;

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use App\Models\ShopSetting;
use App\Models\SiteNavigationItem;
use App\Services\Settings\ShopSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class ShopSettingsSeeder extends Seeder
{
    /** @var array<string, array{zone:NavigationZone,title:string,route_name:string,position:int}> */
    private const NAVIGATION = [
        'partners' => ['zone' => NavigationZone::HeaderTop, 'title' => 'Партнёрам', 'route_name' => 'partners', 'position' => 10],
        'about' => ['zone' => NavigationZone::FooterAbout, 'title' => 'О нас', 'route_name' => 'about', 'position' => 10],
        'how' => ['zone' => NavigationZone::HeaderTop, 'title' => 'Как заказать', 'route_name' => 'how', 'position' => 20],
        'payment' => ['zone' => NavigationZone::FooterDocuments, 'title' => 'Оплата', 'route_name' => 'payment', 'position' => 10],
        'faq' => ['zone' => NavigationZone::FooterAbout, 'title' => 'Вопросы и ответы', 'route_name' => 'faq', 'position' => 20],
        'catalog' => ['zone' => NavigationZone::HeaderMain, 'title' => 'Каталог', 'route_name' => 'catalog.index', 'position' => 10],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            if (! ShopSetting::query()->where('singleton_key', ShopSetting::SINGLETON_KEY)->exists()) {
                ShopSetting::query()->create(ShopSettingsService::defaults());
            }

            foreach (self::NAVIGATION as $code => $definition) {
                if (! Route::has($definition['route_name'])) {
                    continue;
                }

                SiteNavigationItem::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'zone' => $definition['zone'],
                        'title' => $definition['title'],
                        'link_type' => NavigationLinkType::Route,
                        'route_name' => $definition['route_name'],
                        'url' => null,
                        'open_in_new_tab' => false,
                        'is_active' => true,
                        'position' => $definition['position'],
                    ],
                );
            }
        });
    }
}
