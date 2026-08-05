<?php

use App\Models\ShopSetting;
use App\Models\SiteNavigationItem;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('shop settings seeder is idempotent and preserves every manual change', function (): void {
    $this->seed(ShopSettingsSeeder::class);

    $setting = ShopSetting::query()->sole();
    $about = SiteNavigationItem::query()->where('code', 'about')->firstOrFail();
    $setting->forceFill([
        'store_name' => 'Ручное название',
        'phone_display' => 'Ручной телефон',
        'public_email' => 'manual@example.ru',
    ])->save();
    $about->forceFill([
        'title' => 'Ручное название ссылки',
        'position' => 777,
        'is_active' => false,
        'route_name' => 'faq',
    ])->save();
    $counts = [ShopSetting::query()->count(), SiteNavigationItem::query()->count()];

    $this->seed(ShopSettingsSeeder::class);

    expect(ShopSetting::query()->count())->toBe($counts[0])
        ->and(SiteNavigationItem::query()->count())->toBe($counts[1])
        ->and($setting->refresh()->store_name)->toBe('Ручное название')
        ->and($setting->phone_display)->toBe('Ручной телефон')
        ->and($setting->public_email)->toBe('manual@example.ru')
        ->and($about->refresh()->title)->toBe('Ручное название ссылки')
        ->and($about->position)->toBe(777)
        ->and($about->is_active)->toBeFalse()
        ->and($about->route_name)->toBe('faq');
});

test('shop settings seeder creates only safe values and existing route links', function (): void {
    $this->seed(ShopSettingsSeeder::class);

    $setting = ShopSetting::query()->sole();
    $items = SiteNavigationItem::query()->ordered()->get();

    expect($setting->store_name)->toBe('AVTOPOROGI.ru')
        ->and($setting->phone_display)->toBe('8 800 100 56 25')
        ->and($setting->phone_href)->toBe('+78001005625')
        ->and($setting->phone_caption)->toBe('Бесплатный звонок')
        ->and($setting->public_email)->toBeNull()
        ->and($setting->order_notification_email)->toBeNull()
        ->and($items->pluck('code')->all())->toEqualCanonicalizing([
            'partners',
            'about',
            'how',
            'payment',
            'faq',
            'catalog',
        ])
        ->and($items->pluck('code')->all())->not->toContain('reviews', 'contacts', 'returns', 'documents');

    foreach ($items as $item) {
        expect(Route::has($item->route_name))->toBeTrue();
    }
});
