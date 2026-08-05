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
use App\Models\User;
use App\Services\Homepage\HomepageContentAdminService;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(HomepageContentSeeder::class);
    $this->homepageService = app(HomepageContentAdminService::class);
    $this->homepageAdmin = User::factory()->admin()->create();
});

test('homepage aggregate service updates and normalizes every content type', function (): void {
    $section = HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->firstOrFail();
    $quickLink = HomepageQuickLink::query()->where('code', HomepageQuickLinkCode::Promotions)->firstOrFail();
    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->firstOrFail();
    $metric = HomepageMetric::query()->where('code', HomepageMetricCode::SinceYear)->firstOrFail();

    $updatedSection = $this->homepageService->updateSection($this->homepageAdmin, $section, ['title' => '  Новый заголовок  ', 'position' => 8]);
    $routeLink = $this->homepageService->updateQuickLink($this->homepageAdmin, $quickLink, [
        'title' => '  Новые акции  ',
        'link_type' => NavigationLinkType::Route,
        'route_name' => '  catalog.index  ',
        'position' => 9,
    ]);
    $urlCard = $this->homepageService->updateCategoryCard($this->homepageAdmin, $card, [
        'link_type' => NavigationLinkType::Url,
        'url' => 'https://example.com/catalog?q=sills',
        'open_in_new_tab' => true,
    ]);
    $updatedMetric = $this->homepageService->updateMetric($this->homepageAdmin, $metric, [
        'prefix' => '  ',
        'value' => '  2015  ',
        'suffix' => ' год ',
        'text' => '  Проверенная экспертиза  ',
    ]);

    expect($updatedSection->title)->toBe('Новый заголовок')
        ->and($updatedSection->position)->toBe(8)
        ->and($routeLink->title)->toBe('Новые акции')
        ->and($routeLink->link_type)->toBe(NavigationLinkType::Route)
        ->and($routeLink->route_name)->toBe('catalog.index')
        ->and($routeLink->url)->toBeNull()
        ->and($urlCard->link_type)->toBe(NavigationLinkType::Url)
        ->and($urlCard->route_name)->toBeNull()
        ->and($urlCard->url)->toBe('https://example.com/catalog?q=sills')
        ->and($urlCard->open_in_new_tab)->toBeTrue()
        ->and($updatedMetric->prefix)->toBeNull()
        ->and($updatedMetric->value)->toBe('2015')
        ->and($updatedMetric->suffix)->toBe('год')
        ->and($updatedMetric->text)->toBe('Проверенная экспертиза');

    $withoutDestination = $this->homepageService->updateQuickLink($this->homepageAdmin, $routeLink, ['link_type' => null]);
    expect($withoutDestination->link_type)->toBeNull()
        ->and($withoutDestination->route_name)->toBeNull()
        ->and($withoutDestination->url)->toBeNull();
});

test('homepage service rejects forged fields html invalid destinations and partial writes', function (): void {
    $section = HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->firstOrFail();
    $link = HomepageQuickLink::query()->where('code', HomepageQuickLinkCode::Promotions)->firstOrFail();
    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->firstOrFail();
    $metric = HomepageMetric::query()->where('code', HomepageMetricCode::SinceYear)->firstOrFail();

    $cases = [
        fn () => $this->homepageService->updateSection($this->homepageAdmin, $section, ['title' => '<b>Секция</b>']),
        fn () => $this->homepageService->updateSection($this->homepageAdmin, $section, ['unknown' => true]),
        fn () => $this->homepageService->updateQuickLink($this->homepageAdmin, $link, ['title' => '<br>Ссылка']),
        fn () => $this->homepageService->updateQuickLink($this->homepageAdmin, $link, ['code' => HomepageQuickLinkCode::Reviews->value]),
        fn () => $this->homepageService->updateQuickLink($this->homepageAdmin, $link, ['link_type' => null, 'route_name' => 'catalog.index']),
        fn () => $this->homepageService->updateQuickLink($this->homepageAdmin, $link, ['link_type' => NavigationLinkType::Route, 'route_name' => 'missing.route']),
        fn () => $this->homepageService->updateCategoryCard($this->homepageAdmin, $card, ['link_type' => NavigationLinkType::Route, 'route_name' => 'catalog.index', 'url' => 'https://example.com']),
        fn () => $this->homepageService->updateMetric($this->homepageAdmin, $metric, ['text' => '<em>Текст</em>']),
        fn () => $this->homepageService->updateMetric($this->homepageAdmin, $metric, ['value' => str_repeat('1', 65)]),
    ];
    $before = [
        $section->getAttributes(),
        $link->getAttributes(),
        $card->getAttributes(),
        $metric->getAttributes(),
    ];

    foreach ($cases as $case) {
        expect($case)->toThrow(ValidationException::class);
        expect($section->fresh()->getAttributes())->toEqual($before[0])
            ->and($link->fresh()->getAttributes())->toEqual($before[1])
            ->and($card->fresh()->getAttributes())->toEqual($before[2])
            ->and($metric->fresh()->getAttributes())->toEqual($before[3]);
    }
});

test('homepage destinations accept only route null or absolute safe http urls', function (string $url, bool $allowed): void {
    $link = HomepageQuickLink::query()->where('code', HomepageQuickLinkCode::Promotions)->firstOrFail();
    $operation = fn () => $this->homepageService->updateQuickLink($this->homepageAdmin, $link, [
        'link_type' => NavigationLinkType::Url,
        'url' => $url,
    ]);

    if ($allowed) {
        expect($operation()->url)->toBe($url);
    } else {
        expect($operation)->toThrow(ValidationException::class);
        expect($link->refresh()->link_type)->toBeNull()
            ->and($link->url)->toBeNull();
    }
})->with([
    'https' => ['https://example.com/path', true],
    'http' => ['http://example.com/path', true],
    'javascript' => ['javascript:alert(1)', false],
    'data' => ['data:text/html,bad', false],
    'file' => ['file:///etc/passwd', false],
    'protocol relative' => ['//example.com/path', false],
]);

test('homepage reorder is complete strict sequential and atomic for every collection', function (): void {
    $collections = [
        [HomepageSection::class, 'reorderSections'],
        [HomepageQuickLink::class, 'reorderQuickLinks'],
        [HomepageCategoryCard::class, 'reorderCategoryCards'],
        [HomepageMetric::class, 'reorderMetrics'],
    ];

    foreach ($collections as [$model, $method]) {
        $ids = $model::query()->ordered()->pluck('id')->reverse()->values()->all();
        $this->homepageService->{$method}($this->homepageAdmin, $ids);
        expect($model::query()->ordered()->pluck('id')->all())->toBe($ids)
            ->and($model::query()->orderBy('position')->pluck('position')->all())->toBe(range(0, count($ids) - 1));

        $before = $model::query()->orderBy('id')->pluck('position', 'id')->all();
        foreach ([
            array_slice($ids, 1),
            [...$ids, $ids[0]],
            [...array_slice($ids, 0, -1), 999999],
            array_map('strval', $ids),
        ] as $invalidIds) {
            expect(fn () => $this->homepageService->{$method}($this->homepageAdmin, $invalidIds))->toThrow(ValidationException::class);
            expect($model::query()->orderBy('id')->pluck('position', 'id')->all())->toBe($before);
        }
    }
});

test('homepage model guards reject unknown mutable copied and deleted records', function (): void {
    $models = [
        [HomepageSection::query()->firstOrFail(), HomepageSectionCode::AboutMetrics],
        [HomepageQuickLink::query()->firstOrFail(), HomepageQuickLinkCode::Fitting],
        [HomepageCategoryCard::query()->firstOrFail(), HomepageCategoryCardCode::RearArches],
        [HomepageMetric::query()->firstOrFail(), HomepageMetricCode::PriceAdvantage],
    ];

    foreach ($models as [$model, $otherCode]) {
        expect(fn () => $model->code = 'unknown')->toThrow(ValidationException::class);
        $model->code = $otherCode;
        expect(fn () => $model->save())->toThrow(ValidationException::class)
            ->and(fn () => $model->delete())->toThrow(ValidationException::class)
            ->and(fn () => $model->forceDelete())->toThrow(ValidationException::class)
            ->and(fn () => $model->replicate())->toThrow(ValidationException::class);
    }
});

test('manager and forbidden actors cannot bypass homepage service authorization', function (User $actor): void {
    $section = HomepageSection::query()->firstOrFail();
    $link = HomepageQuickLink::query()->firstOrFail();
    $card = HomepageCategoryCard::query()->firstOrFail();
    $metric = HomepageMetric::query()->firstOrFail();
    $operations = [
        fn () => $this->homepageService->updateSection($actor, $section, ['title' => 'Нет']),
        fn () => $this->homepageService->setSectionActive($actor, $section, false),
        fn () => $this->homepageService->reorderSections($actor, HomepageSection::query()->pluck('id')->all()),
        fn () => $this->homepageService->updateQuickLink($actor, $link, ['title' => 'Нет']),
        fn () => $this->homepageService->setQuickLinkActive($actor, $link, false),
        fn () => $this->homepageService->reorderQuickLinks($actor, HomepageQuickLink::query()->pluck('id')->all()),
        fn () => $this->homepageService->updateCategoryCard($actor, $card, ['title' => 'Нет']),
        fn () => $this->homepageService->setCategoryCardActive($actor, $card, false),
        fn () => $this->homepageService->reorderCategoryCards($actor, HomepageCategoryCard::query()->pluck('id')->all()),
        fn () => $this->homepageService->updateMetric($actor, $metric, ['text' => 'Нет']),
        fn () => $this->homepageService->setMetricActive($actor, $metric, false),
        fn () => $this->homepageService->reorderMetrics($actor, HomepageMetric::query()->pluck('id')->all()),
    ];

    foreach ($operations as $operation) {
        expect($operation)->toThrow(AuthorizationException::class);
    }

    expect($section->refresh()->is_active)->toBeTrue()
        ->and($link->refresh()->is_active)->toBeTrue()
        ->and($card->refresh()->is_active)->toBeTrue()
        ->and($metric->refresh()->is_active)->toBeTrue();
})->with([
    'manager' => fn () => User::factory()->manager()->create(),
    'customer' => fn () => User::factory()->create(),
    'inactive admin' => fn () => User::factory()->admin()->inactive()->create(),
    'blocked admin' => fn () => User::factory()->admin()->blocked()->create(),
]);
