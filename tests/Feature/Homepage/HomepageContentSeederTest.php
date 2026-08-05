<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageQuickLinkCode;
use App\Enums\HomepageSectionCode;
use App\Enums\NavigationLinkType;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageQuickLink;
use App\Models\HomepageSection;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('homepage content seeder is idempotent and creates exact enum backed defaults', function (): void {
    expect(Route::has('catalog.index'))->toBeTrue();
    $this->seed(HomepageContentSeeder::class);
    $this->seed(HomepageContentSeeder::class);

    expect(HomepageSection::query()->count())->toBe(4)
        ->and(HomepageQuickLink::query()->count())->toBe(7)
        ->and(HomepageCategoryCard::query()->count())->toBe(5)
        ->and(HomepageMetric::query()->count())->toBe(5)
        ->and(HomepageSection::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageSectionCode::cases(), 'value'))
        ->and(HomepageQuickLink::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageQuickLinkCode::cases(), 'value'))
        ->and(HomepageCategoryCard::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageCategoryCardCode::cases(), 'value'))
        ->and(HomepageMetric::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageMetricCode::cases(), 'value'));

    expect(HomepageQuickLink::query()->whereNotNull('link_type')->exists())->toBeFalse()
        ->and(HomepageQuickLink::query()->whereNotNull('route_name')->exists())->toBeFalse()
        ->and(HomepageQuickLink::query()->whereNotNull('url')->exists())->toBeFalse();

    foreach (HomepageCategoryCard::query()->get() as $card) {
        expect($card->link_type)->toBe(NavigationLinkType::Route)
            ->and($card->route_name)->toBe('catalog.index')
            ->and($card->url)->toBeNull();
    }

    $sinceYear = HomepageMetric::query()->where('code', HomepageMetricCode::SinceYear)->firstOrFail();

    expect(HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->value('title'))->toBe('Быстрый поиск запчастей')
        ->and($sinceYear->prefix)->toBe('с')
        ->and($sinceYear->value)->toBe('2014')
        ->and($sinceYear->suffix)->toBe('г.');
});

test('homepage content seeder preserves every manual field and unrelated rows', function (): void {
    $this->seed(HomepageContentSeeder::class);
    HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->update(['title' => 'Ручная секция', 'is_active' => false, 'position' => 99]);
    HomepageQuickLink::query()->where('code', HomepageQuickLinkCode::Promotions)->update([
        'title' => 'Ручная ссылка',
        'link_type' => NavigationLinkType::Url->value,
        'url' => 'https://example.com/promo',
        'open_in_new_tab' => true,
        'is_active' => false,
        'position' => 98,
    ]);
    HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->update([
        'title' => 'Ручная категория',
        'link_type' => null,
        'route_name' => null,
        'is_active' => false,
        'position' => 97,
    ]);
    HomepageMetric::query()->where('code', HomepageMetricCode::ItemsSold)->update([
        'prefix' => 'около',
        'value' => '2',
        'suffix' => 'млн',
        'text' => 'Ручной текст',
        'is_active' => false,
        'position' => 96,
    ]);

    foreach (['homepage_sections', 'homepage_quick_links', 'homepage_category_cards', 'homepage_metrics'] as $table) {
        $attributes = ['code' => 'legacy', 'is_active' => false, 'position' => 999, 'created_at' => now(), 'updated_at' => now()];
        if ($table === 'homepage_sections') {
            $attributes['title'] = 'Legacy';
        } elseif ($table === 'homepage_metrics') {
            $attributes += ['value' => '1', 'text' => 'Legacy'];
        } else {
            $attributes['title'] = 'Legacy';
        }
        DB::table($table)->insert($attributes);
    }

    $this->seed(HomepageContentSeeder::class);

    expect(DB::table('homepage_sections')->where('code', 'vehicle_search')->first())->toMatchObject(['title' => 'Ручная секция', 'is_active' => 0, 'position' => 99])
        ->and(DB::table('homepage_quick_links')->where('code', 'promotions')->first())->toMatchObject(['title' => 'Ручная ссылка', 'link_type' => 'url', 'url' => 'https://example.com/promo', 'open_in_new_tab' => 1, 'is_active' => 0, 'position' => 98])
        ->and(DB::table('homepage_category_cards')->where('code', 'commercial')->first())->toMatchObject(['title' => 'Ручная категория', 'link_type' => null, 'route_name' => null, 'is_active' => 0, 'position' => 97])
        ->and(DB::table('homepage_metrics')->where('code', 'items_sold')->first())->toMatchObject(['prefix' => 'около', 'value' => '2', 'suffix' => 'млн', 'text' => 'Ручной текст', 'is_active' => 0, 'position' => 96]);

    foreach (['homepage_sections', 'homepage_quick_links', 'homepage_category_cards', 'homepage_metrics'] as $table) {
        expect(DB::table($table)->where('code', 'legacy')->exists(), $table)->toBeTrue();
    }
});

test('homepage content seeder checks the required catalog route before any write', function (): void {
    Route::partialMock()->shouldReceive('has')->once()->with('catalog.index')->andReturnFalse();

    expect(fn () => $this->seed(HomepageContentSeeder::class))->toThrow(RuntimeException::class);

    expect(HomepageSection::query()->count())->toBe(0)
        ->and(HomepageQuickLink::query()->count())->toBe(0)
        ->and(HomepageCategoryCard::query()->count())->toBe(0)
        ->and(HomepageMetric::query()->count())->toBe(0);
});
