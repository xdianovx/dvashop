<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageCategoryDestination;
use App\Enums\NavigationLinkType;
use App\Models\HomepageCategoryCard;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Homepage\HomepageContentAdminService;
use App\Services\SiteContent\SitePageContentAdminService;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('homepage category editor exposes only structured destination choices', function (): void {
    expect(SitePageContentAdminService::categoryCardDestinationOptions())->toBe([
        HomepageCategoryDestination::Catalog->value => 'Весь каталог',
        HomepageCategoryDestination::ProductCategory->value => 'Категория магазина',
        HomepageCategoryDestination::PartType->value => 'Тип детали',
    ]);

    $source = file_get_contents(app_path('Filament/Pages/SiteContent/EditHomepagePage.php'));

    expect($source)->not->toBeFalse()
        ->and($source)->not->toContain("TextInput::make('route_name')")
        ->and($source)->not->toContain("Select::make('route_name')")
        ->and($source)->not->toContain("TextInput::make('url')")
        ->and($source)->not->toContain("Select::make('url')");
});

test('homepage cards resolve exact existing catalog targets including the commercial catalog route', function (): void {
    $sill = PartType::factory()->create(['title' => 'Порог']);
    $arch = PartType::factory()->create(['title' => 'Арка']);
    $front = PartType::factory()->childOf($arch)->create(['title' => 'Передняя']);
    $rear = PartType::factory()->childOf($arch)->create(['title' => 'Задняя']);
    $body = ProductCategory::factory()->create(['title' => 'Кузовные детали', 'slug' => 'kuzovnye-detali']);
    $repair = ProductCategory::factory()->forParent($body)->create([
        'title' => 'Ремонтные элементы кузова',
        'slug' => 'remontnye-elementy-kuzova',
    ]);

    $this->seed(HomepageContentSeeder::class);

    $cards = HomepageCategoryCard::query()->get()->keyBy(
        fn (HomepageCategoryCard $card): string => $card->code->value,
    );

    expect($cards)->toHaveCount(5)
        ->and($cards[HomepageCategoryCardCode::Sills->value]->part_type_id)->toBe($sill->getKey())
        ->and($cards[HomepageCategoryCardCode::FrontArches->value]->part_type_id)->toBe($front->getKey())
        ->and($cards[HomepageCategoryCardCode::RearArches->value]->part_type_id)->toBe($rear->getKey())
        ->and($cards[HomepageCategoryCardCode::BodyRepair->value]->product_category_id)->toBe($repair->getKey())
        ->and($cards[HomepageCategoryCardCode::Commercial->value]->is_active)->toBeTrue()
        ->and($cards[HomepageCategoryCardCode::Commercial->value]->link_type)->toBe(NavigationLinkType::Route)
        ->and($cards[HomepageCategoryCardCode::Commercial->value]->route_name)->toBe('catalog.index')
        ->and(route($cards[HomepageCategoryCardCode::Commercial->value]->route_name))->toBe(route('catalog.index'))
        ->and($cards[HomepageCategoryCardCode::Commercial->value]->product_category_id)->toBeNull()
        ->and($cards[HomepageCategoryCardCode::Commercial->value]->part_type_id)->toBeNull();

    foreach ($cards as $card) {
        expect($card->url)->toBeNull()
            ->and($card->open_in_new_tab)->toBeFalse()
            ->and($card->product_category_id !== null && $card->part_type_id !== null)->toBeFalse();

        if ($card->is_active && $card->product_category_id !== null) {
            expect($card->productCategory)->not->toBeNull()
                ->and($card->productCategory->trashed())->toBeFalse()
                ->and($card->productCategory->is_active)->toBeTrue();
        }

        if ($card->is_active && $card->part_type_id !== null) {
            expect($card->partType)->not->toBeNull()
                ->and($card->partType->trashed())->toBeFalse()
                ->and($card->partType->is_active)->toBeTrue();
        }
    }
});

test('missing homepage relation targets create no catalog records while commercial stays available', function (): void {
    $before = [ProductCategory::query()->count(), PartType::query()->count()];

    $this->seed(HomepageContentSeeder::class);

    expect([ProductCategory::query()->count(), PartType::query()->count()])->toBe($before)
        ->and(HomepageCategoryCard::query()->where('is_active', true)->pluck('code')->map->value->all())->toBe([
            HomepageCategoryCardCode::Commercial->value,
        ])
        ->and(HomepageCategoryCard::query()->whereNotNull('product_category_id')->exists())->toBeFalse()
        ->and(HomepageCategoryCard::query()->whereNotNull('part_type_id')->exists())->toBeFalse();
});

test('homepage card service rejects conflicting and arbitrary inactive targets', function (): void {
    $sill = PartType::factory()->create(['title' => 'Порог']);
    $inactivePartType = PartType::factory()->inactive()->create(['title' => 'Неактивный тип']);
    $category = ProductCategory::factory()->create(['title' => 'Категория']);
    $this->seed(HomepageContentSeeder::class);

    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->firstOrFail();
    $admin = User::factory()->admin()->create();
    $service = app(HomepageContentAdminService::class);

    expect(fn () => $service->updateCategoryCard($admin, $card, [
        'link_type' => null,
        'route_name' => null,
        'product_category_id' => $category->getKey(),
        'part_type_id' => $sill->getKey(),
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->updateCategoryCard($admin, $card, [
        'link_type' => null,
        'route_name' => null,
        'product_category_id' => null,
        'part_type_id' => $inactivePartType->getKey(),
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->updateCategoryCard($admin, $card, [
        'link_type' => NavigationLinkType::Url,
        'url' => 'https://example.com',
    ]))->toThrow(ValidationException::class);
});

test('current inactive or deleted homepage card relation remains selectable but card stays inactive', function (string $state): void {
    $sill = PartType::factory()->create(['title' => 'Порог']);
    $this->seed(HomepageContentSeeder::class);
    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->firstOrFail();

    if ($state === 'deleted') {
        $sill->delete();
    } else {
        $sill->forceFill(['is_active' => false])->save();
    }

    $updated = app(HomepageContentAdminService::class)->updateCategoryCard(
        User::factory()->admin()->create(),
        $card,
        ['title' => 'Обновлённые пороги', 'is_active' => true],
    );

    expect($updated->part_type_id)->toBe($sill->getKey())
        ->and($updated->title)->toBe('Обновлённые пороги')
        ->and($updated->is_active)->toBeFalse();
})->with(['inactive' => ['inactive'], 'deleted' => ['deleted']]);

test('homepage catalog option loaders include the current record for every lifecycle state', function (string $state): void {
    $category = ProductCategory::factory()->create([
        'title' => 'Тестовая категория',
    ]);

    $partType = PartType::factory()->create([
        'title' => 'Тестовый тип',
    ]);

    if ($state === 'inactive') {
        $category->forceFill(['is_active' => false])->save();
        $partType->forceFill(['is_active' => false])->save();
    }

    if ($state === 'deleted') {
        $category->delete();
        $partType->delete();
    }

    $service = app(SitePageContentAdminService::class);

    $categoryOptions = $service->productCategoryOptions(
        (int) $category->getKey(),
    );

    $partTypeOptions = $service->partTypeOptions(
        (int) $partType->getKey(),
    );

    expect(array_key_exists((int) $category->getKey(), $categoryOptions))->toBeTrue()
        ->and(array_key_exists((int) $partType->getKey(), $partTypeOptions))->toBeTrue();

    if ($state === 'inactive') {
        expect($categoryOptions[(int) $category->getKey()])->toContain('(неактивно)')
            ->and($partTypeOptions[(int) $partType->getKey()])->toContain('(неактивно)');
    }

    if ($state === 'deleted') {
        expect($categoryOptions[(int) $category->getKey()])->toContain('(удалено)')
            ->and($partTypeOptions[(int) $partType->getKey()])->toContain('(удалено)');
    }
})->with(['active', 'inactive', 'deleted']);
