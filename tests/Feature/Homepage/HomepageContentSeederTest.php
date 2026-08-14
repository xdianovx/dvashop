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
use App\Models\PartType;
use App\Models\ProductCategory;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createHomepageDestinationCatalog(): array
{
    $sill = PartType::factory()->create(['title' => 'Порог']);
    $arch = PartType::factory()->create(['title' => 'Арка']);
    $front = PartType::factory()->childOf($arch)->create(['title' => 'Передняя']);
    $rear = PartType::factory()->childOf($arch)->create(['title' => 'Задняя']);
    $body = ProductCategory::factory()->create(['title' => 'Кузовные детали', 'slug' => 'kuzovnye-detali']);
    $repair = ProductCategory::factory()->forParent($body)->create([
        'title' => 'Ремонтные элементы кузова',
        'slug' => 'remontnye-elementy-kuzova',
    ]);

    return compact('sill', 'front', 'rear', 'repair');
}

test('homepage content seeder is idempotent and creates exact editable defaults with safe destinations', function (): void {
    $targets = createHomepageDestinationCatalog();
    $this->seed(HomepageContentSeeder::class);

    $before = [
        'sections' => DB::table('homepage_sections')->orderBy('id')->get(['code', 'title', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all(),
        'links' => DB::table('homepage_quick_links')->orderBy('id')->get(['code', 'title', 'link_type', 'route_name', 'url', 'open_in_new_tab', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all(),
        'cards' => DB::table('homepage_category_cards')->orderBy('id')->get(['code', 'title', 'link_type', 'route_name', 'product_category_id', 'part_type_id', 'url', 'open_in_new_tab', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all(),
        'metrics' => DB::table('homepage_metrics')->orderBy('id')->get(['code', 'prefix', 'value', 'suffix', 'text', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all(),
    ];

    $this->seed(HomepageContentSeeder::class);

    expect(HomepageSection::query()->count())->toBe(4)
        ->and(HomepageQuickLink::query()->count())->toBe(7)
        ->and(HomepageCategoryCard::query()->count())->toBe(5)
        ->and(HomepageMetric::query()->count())->toBe(5)
        ->and(HomepageSection::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageSectionCode::cases(), 'value'))
        ->and(HomepageQuickLink::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageQuickLinkCode::cases(), 'value'))
        ->and(HomepageCategoryCard::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageCategoryCardCode::cases(), 'value'))
        ->and(HomepageMetric::query()->ordered()->pluck('code')->map->value->all())->toBe(array_column(HomepageMetricCode::cases(), 'value'))
        ->and(HomepageQuickLink::query()->where('is_active', true)->exists())->toBeFalse()
        ->and(HomepageQuickLink::query()->whereNotNull('link_type')->exists())->toBeFalse();

    expect(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->firstOrFail()->part_type_id)->toBe($targets['sill']->getKey())
        ->and(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::FrontArches)->firstOrFail()->part_type_id)->toBe($targets['front']->getKey())
        ->and(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::RearArches)->firstOrFail()->part_type_id)->toBe($targets['rear']->getKey())
        ->and(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::BodyRepair)->firstOrFail()->product_category_id)->toBe($targets['repair']->getKey())
        ->and(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->firstOrFail()->is_active)->toBeTrue()
        ->and(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->firstOrFail()->route_name)->toBe('catalog.index');

    $sinceYear = HomepageMetric::query()->where('code', HomepageMetricCode::SinceYear)->firstOrFail();
    expect(HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->value('title'))->toBe('Быстрый поиск запчастей')
        ->and($sinceYear->prefix)->toBe('с')
        ->and($sinceYear->value)->toBe('2014')
        ->and($sinceYear->suffix)->toBe('г.')
        ->and(DB::table('homepage_sections')->orderBy('id')->get(['code', 'title', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all())->toBe($before['sections'])
        ->and(DB::table('homepage_quick_links')->orderBy('id')->get(['code', 'title', 'link_type', 'route_name', 'url', 'open_in_new_tab', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all())->toBe($before['links'])
        ->and(DB::table('homepage_category_cards')->orderBy('id')->get(['code', 'title', 'link_type', 'route_name', 'product_category_id', 'part_type_id', 'url', 'open_in_new_tab', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all())->toBe($before['cards'])
        ->and(DB::table('homepage_metrics')->orderBy('id')->get(['code', 'prefix', 'value', 'suffix', 'text', 'is_active', 'position'])->map(fn ($row): array => (array) $row)->all())->toBe($before['metrics']);
});

test('homepage content seeder preserves every non blank manual field destination and unrelated row', function (): void {
    createHomepageDestinationCatalog();
    $this->seed(HomepageContentSeeder::class);
    $manualPartType = PartType::factory()->create(['title' => 'Ручной тип назначения']);

    HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->update(['title' => 'Ручная секция', 'is_active' => false, 'position' => 99]);
    HomepageQuickLink::query()->where('code', HomepageQuickLinkCode::Promotions)->update([
        'title' => 'Ручная ссылка',
        'link_type' => 'url',
        'url' => 'https://example.com/promo',
        'open_in_new_tab' => true,
        'is_active' => false,
        'position' => 98,
    ]);
    HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->update([
        'title' => 'Ручная категория',
        'part_type_id' => $manualPartType->getKey(),
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

    DB::table('homepage_metrics')->insert([
        'code' => 'legacy',
        'value' => '1',
        'text' => 'Legacy',
        'is_active' => false,
        'position' => 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(HomepageContentSeeder::class);

    expect(DB::table('homepage_sections')->where('code', 'vehicle_search')->first())->toMatchObject(['title' => 'Ручная секция', 'is_active' => 0, 'position' => 99])
        ->and(DB::table('homepage_quick_links')->where('code', 'promotions')->first())->toMatchObject(['title' => 'Ручная ссылка', 'link_type' => 'url', 'url' => 'https://example.com/promo', 'open_in_new_tab' => 1, 'is_active' => 0, 'position' => 98])
        ->and(DB::table('homepage_category_cards')->where('code', 'sills')->first())->toMatchObject(['title' => 'Ручная категория', 'part_type_id' => $manualPartType->getKey(), 'is_active' => 0, 'position' => 97])
        ->and(DB::table('homepage_metrics')->where('code', 'items_sold')->first())->toMatchObject(['prefix' => 'около', 'value' => '2', 'suffix' => 'млн', 'text' => 'Ручной текст', 'is_active' => 0, 'position' => 96])
        ->and(DB::table('homepage_metrics')->where('code', 'legacy')->exists())->toBeTrue();
});

test('homepage content seeder fills a completely absent destination without changing manual activity', function (): void {
    $targets = createHomepageDestinationCatalog();
    $this->seed(HomepageContentSeeder::class);

    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->firstOrFail();
    $card->forceFill([
        'title' => '   ',
        'link_type' => null,
        'route_name' => null,
        'product_category_id' => null,
        'part_type_id' => null,
        'url' => null,
        'open_in_new_tab' => false,
        'is_active' => false,
    ])->save();

    DB::table('homepage_category_cards')
        ->where('code', HomepageCategoryCardCode::Commercial->value)
        ->update([
            'title' => '   ',
            'link_type' => null,
            'route_name' => null,
            'product_category_id' => null,
            'part_type_id' => null,
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => true,
        ]);

    $this->seed(HomepageContentSeeder::class);

    expect($card->refresh()->title)->toBe('Кузовные пороги')
        ->and($card->link_type)->toBeNull()
        ->and($card->route_name)->toBeNull()
        ->and($card->product_category_id)->toBeNull()
        ->and($card->part_type_id)->toBe($targets['sill']->getKey())
        ->and($card->is_active)->toBeFalse()
        ->and(DB::table('homepage_category_cards')->where('code', HomepageCategoryCardCode::Commercial->value)->first())->toMatchObject([
            'title' => 'Коммерческий транспорт',
            'is_active' => 1,
            'link_type' => null,
            'route_name' => null,
            'product_category_id' => null,
            'part_type_id' => null,
        ]);
});

test('homepage content seeder preserves existing catalog routes instead of replacing them with structured targets', function (): void {
    createHomepageDestinationCatalog();

    foreach ([
        HomepageCategoryCardCode::Sills->value => ['Кузовные пороги', 10, true],
        HomepageCategoryCardCode::Commercial->value => ['Коммерческий транспорт', 20, false],
        HomepageCategoryCardCode::BodyRepair->value => ['Ремонт кузова', 30, true],
        HomepageCategoryCardCode::FrontArches->value => ['Передние арки', 40, true],
        HomepageCategoryCardCode::RearArches->value => ['Задние арки', 50, true],
    ] as $code => [$title, $position, $isActive]) {
        HomepageCategoryCard::query()->create([
            'code' => $code,
            'title' => $title,
            'link_type' => 'route',
            'route_name' => 'catalog.index',
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => $isActive,
            'position' => $position,
        ]);
    }

    $this->seed(HomepageContentSeeder::class);

    foreach (HomepageCategoryCard::query()->get() as $card) {
        expect($card->link_type?->value, $card->code->value)->toBe('route')
            ->and($card->route_name, $card->code->value)->toBe('catalog.index')
            ->and($card->product_category_id, $card->code->value)->toBeNull()
            ->and($card->part_type_id, $card->code->value)->toBeNull();
    }

    expect(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->value('is_active'))->toBeFalse();
});

test('missing exact catalog targets leave new cards inactive and seeder never creates catalog records', function (): void {
    $before = [ProductCategory::query()->count(), PartType::query()->count()];
    $this->seed(HomepageContentSeeder::class);

    expect([ProductCategory::query()->count(), PartType::query()->count()])->toBe($before)
        ->and(HomepageCategoryCard::query()->where('is_active', true)->pluck('code')->map->value->all())->toBe([
            HomepageCategoryCardCode::Commercial->value,
        ])
        ->and(HomepageCategoryCard::query()->whereNotNull('product_category_id')->exists())->toBeFalse()
        ->and(HomepageCategoryCard::query()->whereNotNull('part_type_id')->exists())->toBeFalse();
});

test('homepage content seeder upgrades only the exact untouched legacy commercial card', function (): void {
    HomepageCategoryCard::query()->create([
        'code' => HomepageCategoryCardCode::Commercial,
        'title' => 'Коммерческий транспорт',
        'link_type' => null,
        'route_name' => null,
        'product_category_id' => null,
        'part_type_id' => null,
        'url' => null,
        'open_in_new_tab' => false,
        'is_active' => false,
        'position' => 20,
    ]);

    $this->seed(HomepageContentSeeder::class);

    expect(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->firstOrFail())
        ->is_active->toBeTrue()
        ->link_type->toBe(NavigationLinkType::Route)
        ->route_name->toBe('catalog.index')
        ->url->toBeNull();
});

test('homepage content seeder preserves an explicit commercial admin override', function (): void {
    HomepageCategoryCard::query()->create([
        'code' => HomepageCategoryCardCode::Commercial,
        'title' => 'Коммерческий транспорт для бизнеса',
        'link_type' => null,
        'route_name' => null,
        'product_category_id' => null,
        'part_type_id' => null,
        'url' => null,
        'open_in_new_tab' => false,
        'is_active' => false,
        'position' => 20,
    ]);

    $this->seed(HomepageContentSeeder::class);

    expect(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->firstOrFail())
        ->title->toBe('Коммерческий транспорт для бизнеса')
        ->is_active->toBeFalse()
        ->link_type->toBeNull()
        ->route_name->toBeNull();
});
