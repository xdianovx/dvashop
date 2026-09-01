<?php

use App\Enums\HomepageSectionCode;
use App\Enums\StaticPageCode;
use App\Models\FaqCategory;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([
        CheckoutMethodSettingsSeeder::class,
        HomepageContentSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
    ]);
});

test('aggregate state loaders use a bounded number of queries without n plus one', function (string $method, int $expectedQueries): void {
    $service = app(SitePageContentAdminService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $state = $service->{$method}();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($state)->toBeArray()->not->toBeEmpty()
        ->and($queryCount)->toBe($expectedQueries);
})->with([
    'homepage' => ['homepageState', 4],
    'about' => ['aboutState', 3],
    'how' => ['howState', 3],
    'payment and delivery' => ['paymentState', 2],
    'faq' => ['faqState', 2],
    'partners' => ['partnersState', 3],
]);

test('homepage story state stays bounded with many groups and items', function (): void {
    HomepageStoryGroup::factory()->count(10)->create()->each(function (HomepageStoryGroup $group): void {
        HomepageStoryItem::factory()->count(5)->for($group, 'group')->create();
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    $state = app(SitePageContentAdminService::class)->homepageState();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($state['stories'])->toHaveCount(10)
        ->and($state['stories'][0]['items'])->toHaveCount(5)
        ->and($queryCount)->toBe(5);
});

test('homepage save is atomic when a later fixed row fails validation', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $state = $service->homepageState();
    $sectionId = $state['search_section']['id'];
    $originalTitle = HomepageSection::query()->findOrFail($sectionId)->title;

    $state['search_section']['title'] = 'Это изменение должно откатиться';
    $state['category_cards'][0]['title'] = '<script>ошибка</script>';

    try {
        $service->saveHomepage($admin, $state);
        $this->fail('Ожидалась ошибка валидации HTML.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('category_cards.0.title');
    }

    expect(HomepageSection::query()->findOrFail($sectionId)->title)->toBe($originalTitle);
});

test('every page aggregate rolls back earlier updates when a later field is invalid', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();

    $cases = [
        ['aboutState', 'saveAbout', function (array &$state): void {
            $state['hero']['title'] = 'Не должно сохраниться';
            $state['metrics'][0]['title'] = '<b>Ошибка</b>';
        }],
        ['howState', 'saveHow', function (array &$state): void {
            $state['steps'][0]['title'] = 'Не должно сохраниться';
            $state['steps'][1]['title'] = '<b>Ошибка</b>';
        }],
        ['paymentState', 'savePayment', function (array &$state): void {
            $state['payment_methods'][0]['page_title'] = 'Не должно сохраниться';
            $state['payment_methods'][1]['page_description'] = '<iframe src="x"></iframe>';
        }],
        ['faqState', 'saveFaq', function (array &$state): void {
            $state['categories'][0]['title'] = 'Не должно сохраниться';
            $state['categories'][1]['items'][0]['answer'] = '<b>Ошибка</b>';
        }],
        ['partnersState', 'savePartners', function (array &$state): void {
            $state['page']['title'] = 'Не должно сохраниться';
            $state['benefits'][0]['title'] = '<b>Ошибка</b>';
        }],
    ];

    foreach ($cases as [$loadMethod, $saveMethod, $mutate]) {
        $before = $service->{$loadMethod}();
        $payload = $before;
        $mutate($payload);

        try {
            $service->{$saveMethod}($admin, $payload);
            $this->fail("Ожидалась ошибка валидации для {$saveMethod}.");
        } catch (ValidationException) {
            // The outer page transaction must roll back all earlier nested service updates.
        }

        expect($service->{$loadMethod}(), $saveMethod)->toBe($before);
    }
});

test('forged fields are rejected and fixed homepage codes stay unchanged', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $state = $service->homepageState();
    $before = HomepageSection::query()->orderBy('id')->get(['id', 'code', 'position', 'title'])->toArray();
    $state['stories_section']['code'] = 'forged_code';

    try {
        $service->saveHomepage($admin, $state);
        $this->fail('Ожидалась ошибка whitelist.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('stories_section.code');
    }

    expect(HomepageSection::query()->orderBy('id')->get(['id', 'code', 'position', 'title'])->toArray())->toBe($before);
});

test('fixed static item codes parents and positions cannot be changed through page editor payload', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $state = $service->howState();
    $before = StaticPageItem::query()
        ->whereHas('section.page', fn ($query) => $query->where('code', StaticPageCode::How->value))
        ->orderBy('id')
        ->get(['id', 'code', 'static_page_section_id', 'position'])
        ->toArray();

    foreach ($state['steps'] as $index => &$step) {
        $step['title'] = 'Обновлённый шаг '.($index + 1);
        $step['text'] = 'Обновлённое описание '.($index + 1);
    }
    unset($step);

    $service->saveHow($admin, $state);

    expect(StaticPageItem::query()
        ->whereHas('section.page', fn ($query) => $query->where('code', StaticPageCode::How->value))
        ->orderBy('id')
        ->get(['id', 'code', 'static_page_section_id', 'position'])
        ->toArray())->toBe($before);
});

test('all fixed page saves preserve system codes parents positions and record counts', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $before = [
        'pages' => StaticPage::query()->orderBy('id')->get(['id', 'code', 'position'])->toArray(),
        'sections' => StaticPageSection::query()->orderBy('id')->get(['id', 'static_page_id', 'code', 'position'])->toArray(),
        'items' => StaticPageItem::query()->orderBy('id')->get(['id', 'static_page_section_id', 'code', 'position'])->toArray(),
    ];

    $about = $service->aboutState();
    $about['hero']['title'] = 'Обновлённый первый экран';
    $service->saveAbout($admin, $about);

    $how = $service->howState();
    $how['steps'][0]['title'] = 'Обновлённый первый шаг';
    $service->saveHow($admin, $how);

    $partners = $service->partnersState();
    $partners['page']['title'] = 'Обновлённая страница партнёров';
    $service->savePartners($admin, $partners);

    expect([
        'pages' => StaticPage::query()->orderBy('id')->get(['id', 'code', 'position'])->toArray(),
        'sections' => StaticPageSection::query()->orderBy('id')->get(['id', 'static_page_id', 'code', 'position'])->toArray(),
        'items' => StaticPageItem::query()->orderBy('id')->get(['id', 'static_page_section_id', 'code', 'position'])->toArray(),
    ])->toBe($before);
});

test('fixed collections reject omitted records without partial updates', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();

    $homepage = $service->homepageState();
    $homepageBefore = $homepage;
    array_pop($homepage['metrics']);

    try {
        $service->saveHomepage($admin, $homepage);
        $this->fail('Ожидалась ошибка полного фиксированного набора показателей.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('metrics');
    }
    expect($service->homepageState())->toBe($homepageBefore);

    $how = $service->howState();
    $howBefore = $how;
    array_pop($how['steps']);

    try {
        $service->saveHow($admin, $how);
        $this->fail('Ожидалась ошибка полного фиксированного набора шагов.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('steps');
    }
    expect($service->howState())->toBe($howBefore);
});

test('faq aggregate creates updates moves reorders and soft deletes in one save', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $state = $service->faqState();
    $deletedCategoryId = $state['categories'][0]['id'];
    array_shift($state['categories']);
    $state['categories'][0]['title'] = 'Обновлённая категория';
    $state['categories'][0]['items'][] = [
        'id' => null,
        'question' => 'Новый вопрос?',
        'answer' => 'Новый ответ.',
        'is_active' => true,
        'is_featured' => false,
    ];
    $state['categories'][] = [
        'id' => null,
        'title' => 'Новая категория',
        'is_active' => true,
        'items' => [],
    ];

    $service->saveFaq($admin, $state);

    expect(FaqCategory::withTrashed()->findOrFail($deletedCategoryId)->trashed())->toBeTrue()
        ->and(FaqCategory::query()->where('title', 'Обновлённая категория')->exists())->toBeTrue()
        ->and(FaqCategory::query()->where('title', 'Новая категория')->exists())->toBeTrue()
        ->and(DB::table('faq_items')->where('question', 'Новый вопрос?')->whereNull('deleted_at')->exists())->toBeTrue();
});

test('manager cannot save any aggregate page even with a forged direct service call', function (string $loadMethod, string $saveMethod): void {
    $service = app(SitePageContentAdminService::class);
    $manager = User::factory()->manager()->create();

    $this->expectException(AuthorizationException::class);
    $service->{$saveMethod}($manager, $service->{$loadMethod}());
})->with([
    'homepage' => ['homepageState', 'saveHomepage'],
    'about' => ['aboutState', 'saveAbout'],
    'how' => ['howState', 'saveHow'],
    'payment' => ['paymentState', 'savePayment'],
    'faq' => ['faqState', 'saveFaq'],
    'partners' => ['partnersState', 'savePartners'],
]);

test('homepage state returns five semantic sections in system order with one bounded section query', function (): void {
    $service = app(SitePageContentAdminService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $state = $service->homepageState();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(array_keys($state))->toContain(
        'stories_section', 'stories', 'search_section', 'category_section',
        'category_cards', 'reviews_section', 'about_section', 'metrics',
    )->and(HomepageSection::query()->ordered()->pluck('code')->map->value->all())->toBe([
        'stories', 'vehicle_search', 'category_cards', 'reviews', 'about_metrics',
    ])->and([
        $state['stories_section']['_label'],
        $state['search_section']['_label'],
        $state['category_section']['_label'],
        $state['reviews_section']['_label'],
        $state['about_section']['_label'],
    ])->toBe(['Сторис', 'Быстрый поиск запчастей', 'Витринные категории', 'Отзывы клиентов', 'О компании'])
        ->and(collect($queries)->filter(
            fn (array $query): bool => str_contains($query['query'], 'homepage_sections'),
        ))->toHaveCount(1);
});

test('admin and super admin update homepage section titles and activity without changing codes or positions', function (string $role): void {
    $service = app(SitePageContentAdminService::class);
    $actor = $role === 'super_admin'
        ? User::factory()->superAdmin()->create()
        : User::factory()->admin()->create();
    $beforeStructure = HomepageSection::query()->ordered()->get(['id', 'code', 'position'])->toArray();
    $state = $service->homepageState();

    $state['stories_section']['is_active'] = false;
    $state['search_section']['title'] = "{$role} поиск";
    $state['reviews_section']['title'] = "{$role} отзывы";
    $state['about_section']['title'] = "{$role} компания";

    $service->saveHomepage($actor, $state);

    expect(HomepageSection::query()->ordered()->pluck('title')->all())->toBe([
        null, "{$role} поиск", null, "{$role} отзывы", "{$role} компания",
    ])->and(HomepageSection::query()->ordered()->get()->map(
        fn (HomepageSection $section): bool => $section->is_active,
    )->all())->toBe([false, true, true, true, true])
        ->and(HomepageSection::query()->ordered()->get(['id', 'code', 'position'])->toArray())->toBe($beforeStructure);
})->with(['super admin' => ['super_admin'], 'admin' => ['admin']]);

test('homepage sections reject omissions foreign ids and forged code or position without partial writes', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $before = $service->homepageState();

    $cases = [
        'foreign id' => [function (array $state): array {
            $state['reviews_section']['id'] = 999999;

            return $state;
        }, 'reviews_section.id'],
        'omitted section' => [function (array $state): array {
            $state['reviews_section'] = null;

            return $state;
        }, 'reviews_section'],
        'forged code' => [function (array $state): array {
            $state['reviews_section']['code'] = HomepageSectionCode::AboutMetrics->value;

            return $state;
        }, 'reviews_section.code'],
        'forged position' => [function (array $state): array {
            $state['reviews_section']['position'] = 999;

            return $state;
        }, 'reviews_section.position'],
    ];

    foreach ($cases as $label => [$mutate, $errorKey]) {
        try {
            $service->saveHomepage($admin, $mutate($before));
            $this->fail("Ожидалась ошибка для {$label}.");
        } catch (ValidationException $exception) {
            expect($exception->errors(), $label)->toHaveKey($errorKey);
        }

        expect($service->homepageState(), $label)->toBe($before);
    }
});

test('invalid late homepage section rolls back updates to earlier sections', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $before = $service->homepageState();
    $payload = $before;
    $payload['search_section']['title'] = 'Это изменение должно откатиться';
    $payload['reviews_section']['title'] = '<script>ошибка</script>';

    try {
        $service->saveHomepage($admin, $payload);
        $this->fail('Ожидалась ошибка HTML в поздней секции.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('reviews_section.title');
    }

    expect($service->homepageState())->toBe($before);
});
