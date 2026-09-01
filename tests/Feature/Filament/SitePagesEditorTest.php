<?php

use App\Enums\HomepageSectionCode;
use App\Filament\Pages\SiteContent\EditAboutPage;
use App\Filament\Pages\SiteContent\EditFaqPage;
use App\Filament\Pages\SiteContent\EditHomepagePage;
use App\Filament\Pages\SiteContent\EditHowPage;
use App\Filament\Pages\SiteContent\EditPartnersPage;
use App\Filament\Pages\SiteContent\EditPaymentPage;
use App\Filament\Pages\SitePagesPage;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

$undoRepeaterFake = null;

beforeEach(function () use (&$undoRepeaterFake): void {
    $undoRepeaterFake = Repeater::fake();
    $this->seed([
        CheckoutMethodSettingsSeeder::class,
        HomepageContentSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

afterEach(function () use (&$undoRepeaterFake): void {
    if ($undoRepeaterFake instanceof Closure) {
        $undoRepeaterFake();
    }
});

function siteEditorDefinitions(): array
{
    return [
        EditHomepagePage::class => [
            'title' => 'Главная',
            'section' => 'Сторис',
            'read_only_field' => 'stories',
            'field' => 'data.metrics.0.text',
            'value' => 'Обновлённый показатель главной',
            'notification' => 'Главная страница сохранена',
            'load' => 'homepageState',
            'state_path' => ['metrics', 0, 'text'],
        ],
        EditAboutPage::class => [
            'title' => 'О нас',
            'section' => 'Первый экран',
            'read_only_field' => 'hero.title',
            'field' => 'data.hero.title',
            'value' => 'Обновлённый заголовок о компании',
            'notification' => 'Страница «О нас» сохранена',
            'load' => 'aboutState',
            'state_path' => ['hero', 'title'],
        ],
        EditHowPage::class => [
            'title' => 'Как мы работаем',
            'section' => 'Шесть шагов',
            'read_only_field' => 'steps',
            'field' => 'data.steps.0.title',
            'value' => 'Обновлённый первый шаг',
            'notification' => 'Страница «Как мы работаем» сохранена',
            'load' => 'howState',
            'state_path' => ['steps', 0, 'title'],
        ],
        EditPaymentPage::class => [
            'title' => 'Оплата и доставка',
            'section' => 'Способы оплаты',
            'read_only_field' => 'payment_methods',
            'field' => 'data.payment_methods.0.title',
            'value' => 'Обновлённый способ оплаты',
            'notification' => 'Способы оплаты и доставки сохранены',
            'load' => 'paymentState',
            'state_path' => ['payment_methods', 0, 'title'],
        ],
        EditFaqPage::class => [
            'title' => 'Вопросы и ответы',
            'section' => 'Категории вопросов',
            'read_only_field' => 'categories',
            'field' => 'data.categories.0.title',
            'value' => 'Обновлённая категория вопросов',
            'notification' => 'Вопросы и ответы сохранены',
            'load' => 'faqState',
            'state_path' => ['categories', 0, 'title'],
        ],
        EditPartnersPage::class => [
            'title' => 'Партнёрам',
            'section' => 'Четыре преимущества',
            'read_only_field' => 'page.title',
            'field' => 'data.page.title',
            'value' => 'Обновлённый заголовок для партнёров',
            'notification' => 'Страница «Партнёрам» сохранена',
            'load' => 'partnersState',
            'state_path' => ['page', 'title'],
        ],
    ];
}

function nestedStateValue(array $state, array $path): mixed
{
    foreach ($path as $segment) {
        $state = $state[$segment];
    }

    return $state;
}

test('site pages index is the single understandable content entry and contains six correct cards', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test(SitePagesPage::class)
        ->assertSee('Страницы сайта')
        ->assertSee('Выберите страницу');

    foreach (siteEditorDefinitions() as $page => $definition) {
        $component
            ->assertSee($definition['title'])
            ->assertSee($page::getUrl(), false);

        $this->get($page::getUrl())
            ->assertOk()
            ->assertSee($definition['title'])
            ->assertSee($definition['section']);
    }
});

test('all editor forms use understandable labels and hide technical structure fields', function (string $page, array $labels): void {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test($page);
    foreach ($labels as $label) {
        $component->assertSee($label);
    }

    $component
        ->assertDontSee('Системный код')
        ->assertDontSee('Числовая позиция')
        ->assertDontSee('Родительский блок');
})->with([
    'homepage' => [EditHomepagePage::class, ['Сторис', 'Кружки', 'Быстрый поиск запчастей', 'Витринные категории', 'Отзывы клиентов', 'О компании', 'Назначение карточки', 'Категория магазина', 'Тип детали', 'Префикс', 'Значение', 'Суффикс']],
    'about' => [EditAboutPage::class, ['Первый экран', 'Показатели', 'Технологии точности', 'Наша цель']],
    'how' => [EditHowPage::class, ['Шесть шагов', 'Название', 'Описание']],
    'payment' => [EditPaymentPage::class, ['Способы оплаты', 'Способы доставки', 'Название в оформлении заказа', 'Краткое описание в оформлении заказа', 'Заголовок на странице «Оплата и доставка»', 'Полное описание на странице «Оплата и доставка»', 'Базовая стоимость']],
    'faq' => [EditFaqPage::class, ['Категории вопросов', 'Название категории', 'Вопросы категории', 'Вопрос', 'Ответ']],
    'partners' => [EditPartnersPage::class, ['Первый экран', 'Четыре преимущества', 'Четыре формата сотрудничества', 'Пять фактов о компании']],
]);

test('admin and super admin can save every fixed page editor with a success notification', function (string $role): void {
    $actor = $role === 'super_admin'
        ? User::factory()->superAdmin()->create()
        : User::factory()->admin()->create();
    $this->actingAs($actor);
    $service = app(SitePageContentAdminService::class);

    foreach (siteEditorDefinitions() as $page => $definition) {
        Livewire::test($page)
            ->set($definition['field'], $definition['value'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertHasNoFormErrors()
            ->assertNotified($definition['notification']);

        $state = $service->{$definition['load']}();
        expect(nestedStateValue($state, $definition['state_path']), "{$role}:{$page}")
            ->toBe($definition['value']);
    }
})->with(['super admin' => ['super_admin'], 'admin' => ['admin']]);

test('manager sees the same editors read only without save button and forged save is forbidden', function (): void {
    $this->actingAs(User::factory()->manager()->create());

    foreach (siteEditorDefinitions() as $page => $definition) {
        $this->get($page::getUrl())
            ->assertOk()
            ->assertSee('Режим просмотра')
            ->assertDontSee('Сохранить изменения');

        Livewire::test($page)
            ->assertFormFieldDisabled($definition['read_only_field'])
            ->set($definition['field'], $definition['value'])
            ->call('save')
            ->assertForbidden();
    }
});

test('customer inactive and blocked users receive forbidden for every content page', function (string $kind): void {
    $user = match ($kind) {
        'inactive' => User::factory()->admin()->inactive()->create(),
        'blocked' => User::factory()->admin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $this->actingAs($user);

    foreach ([SitePagesPage::class, ...array_keys(siteEditorDefinitions())] as $page) {
        $this->get($page::getUrl())->assertForbidden();
    }
})->with(['customer' => ['customer'], 'inactive' => ['inactive'], 'blocked' => ['blocked']]);

test('forged homepage system field is rejected by the aggregate service and nothing is changed', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(SitePageContentAdminService::class);
    $before = $service->homepageState();
    $payload = $before;
    $payload['stories_section']['code'] = 'forged';

    expect(fn () => $service->saveHomepage($admin, $payload))->toThrow(ValidationException::class);

    expect($service->homepageState())->toBe($before);
});

test('homepage editor contains five fixed semantic sections in system order and updates allowed fields', function (string $role): void {
    $actor = $role === 'super_admin'
        ? User::factory()->superAdmin()->create()
        : User::factory()->admin()->create();
    $this->actingAs($actor);
    $service = app(SitePageContentAdminService::class);
    $beforeStructure = HomepageSection::query()->ordered()->get(['id', 'code', 'position'])->toArray();
    $state = $service->homepageState();

    expect(HomepageSection::query()->ordered()->get()->map(
        fn (HomepageSection $section): string => $section->code->value,
    )->all())->toBe([
        HomepageSectionCode::Stories->value,
        HomepageSectionCode::VehicleSearch->value,
        HomepageSectionCode::CategoryCards->value,
        HomepageSectionCode::Reviews->value,
        HomepageSectionCode::AboutMetrics->value,
    ]);

    $state['stories_section']['is_active'] = false;
    $state['search_section']['title'] = "{$role}: поиск";
    $state['reviews_section']['title'] = "{$role}: отзывы";
    $state['about_section']['title'] = "{$role}: компания";

    Livewire::test(EditHomepagePage::class)
        ->set('data', $state)
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified('Главная страница сохранена');

    expect(HomepageSection::query()->ordered()->pluck('title')->all())->toBe([
        null,
        "{$role}: поиск",
        null,
        "{$role}: отзывы",
        "{$role}: компания",
    ])->and(HomepageSection::query()->ordered()->get()->map(
        fn (HomepageSection $section): bool => $section->is_active,
    )->all())->toBe([false, true, true, true, true])
        ->and(HomepageSection::query()->ordered()->get(['id', 'code', 'position'])->toArray())->toBe($beforeStructure);
})->with(['super admin' => ['super_admin'], 'admin' => ['admin']]);

test('manager sees homepage sections read only and cannot forge a save', function (): void {
    $this->actingAs(User::factory()->manager()->create());
    $before = app(SitePageContentAdminService::class)->homepageState();

    Livewire::test(EditHomepagePage::class)
        ->assertFormFieldDisabled('stories')
        ->set('data.reviews_section.title', 'Поддельное изменение')
        ->call('save')
        ->assertForbidden();

    expect(app(SitePageContentAdminService::class)->homepageState())->toBe($before);
});

test('homepage aggregate rejects omitted and forged fixed semantic sections', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(SitePageContentAdminService::class);
    $before = $service->homepageState();

    foreach (['wrong_id', 'omitted', 'code', 'position'] as $case) {
        $payload = $before;
        match ($case) {
            'wrong_id' => $payload['reviews_section']['id'] = 999999,
            'omitted' => $payload['reviews_section'] = null,
            'code' => $payload['reviews_section']['code'] = 'forged',
            'position' => $payload['reviews_section']['position'] = 999,
        };

        expect(fn () => $service->saveHomepage($admin, $payload), $case)
            ->toThrow(ValidationException::class);
    }

    expect($service->homepageState())->toBe($before);
});

test('homepage story form uses separate mime specific upload components normalized to one domain path', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Storage::fake('public');
    Storage::disk('public')->put('uploads/homepage/stories/form-image.jpg', 'image');
    Storage::disk('public')->put('uploads/homepage/stories/form-video.webm', 'video');
    $group = HomepageStoryGroup::factory()->create();
    HomepageStoryItem::factory()->for($group, 'group')->create([
        'media_type' => 'image', 'media_path' => 'uploads/homepage/stories/form-image.jpg', 'position' => 10,
    ]);
    HomepageStoryItem::factory()->for($group, 'group')->create([
        'media_type' => 'video', 'media_path' => 'uploads/homepage/stories/form-video.webm', 'duration_seconds' => null, 'position' => 20,
    ]);

    $component = Livewire::test(EditHomepagePage::class)
        ->assertSet('data.stories.0.items.0.image_media_path', fn (mixed $state): bool => collect($state)->contains('uploads/homepage/stories/form-image.jpg'))
        ->assertSet('data.stories.0.items.0.video_media_path', fn (mixed $state): bool => blank($state))
        ->assertSet('data.stories.0.items.1.image_media_path', fn (mixed $state): bool => blank($state))
        ->assertSet('data.stories.0.items.1.video_media_path', fn (mixed $state): bool => collect($state)->contains('uploads/homepage/stories/form-video.webm'))
        ->set('data.stories.0.items.0.media_type', 'video')
        ->assertSet('data.stories.0.items.0.image_media_path', fn (mixed $state): bool => blank($state))
        ->assertSet('data.stories.0.items.0.video_media_path', fn (mixed $state): bool => blank($state))
        ->assertSet('data.stories.0.items.0.duration_seconds', null)
        ->set('data.stories.0.items.1.media_type', 'image')
        ->assertSet('data.stories.0.items.1.image_media_path', fn (mixed $state): bool => blank($state))
        ->assertSet('data.stories.0.items.1.video_media_path', fn (mixed $state): bool => blank($state))
        ->assertSet('data.stories.0.items.1.duration_seconds', 10);
    unset($component);
    Storage::disk('public')->assertExists('uploads/homepage/stories/form-image.jpg');

    $page = new EditHomepagePage;
    $toUi = new ReflectionMethod($page, 'withStoryUploadFields');
    $toDomain = new ReflectionMethod($page, 'withPersistedStoryMediaPath');
    $uiState = $toUi->invoke($page, ['stories' => [['items' => [
        ['media_type' => 'image', 'media_path' => 'uploads/homepage/stories/form-image.jpg'],
        ['media_type' => 'video', 'media_path' => 'uploads/homepage/stories/form-video.webm'],
    ]]]]);

    expect($uiState['stories'][0]['items'][0])->toMatchArray([
        'image_media_path' => 'uploads/homepage/stories/form-image.jpg',
        'video_media_path' => null,
    ])->not->toHaveKey('media_path')
        ->and($uiState['stories'][0]['items'][1])->toMatchArray([
            'image_media_path' => null,
            'video_media_path' => 'uploads/homepage/stories/form-video.webm',
        ])->not->toHaveKey('media_path');

    $domainState = $toDomain->invoke($page, $uiState);
    expect(array_column($domainState['stories'][0]['items'], 'media_path'))->toBe([
        'uploads/homepage/stories/form-image.jpg',
        'uploads/homepage/stories/form-video.webm',
    ])->and($domainState['stories'][0]['items'][0])->not->toHaveKeys(['image_media_path', 'video_media_path'])
        ->and($domainState['stories'][0]['items'][1])->not->toHaveKeys(['image_media_path', 'video_media_path']);

    $source = file_get_contents(app_path('Filament/Pages/SiteContent/EditHomepagePage.php'));
    $serviceSource = file_get_contents(app_path('Services/Homepage/HomepageContentAdminService.php'));
    expect($source)->not->toBeFalse()
        ->and($source)->toContain("FileUpload::make('image_media_path')")
        ->toContain("->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])")
        ->toContain('->maxSize(10240)')
        ->toContain("FileUpload::make('video_media_path')")
        ->toContain("->acceptedFileTypes(['video/mp4', 'video/webm'])")
        ->toContain('->maxSize(92160)')
        ->toContain("\$set('image_media_path', null)")
        ->toContain("\$set('video_media_path', null)")
        ->not->toContain("FileUpload::make('media_path')")
        ->and($serviceSource)->not->toBeFalse()
        ->toContain('10 * 1024 * 1024')
        ->toContain('90 * 1024 * 1024');
});

test('faq repeater requires confirmation before removing categories and questions from the form', function (): void {
    $source = file_get_contents(app_path('Filament/Pages/SiteContent/EditFaqPage.php'));

    expect($source)->not->toBeFalse()
        ->and(substr_count((string) $source, '->deleteAction('))->toBe(2)
        ->and(substr_count((string) $source, '->requiresConfirmation()'))->toBe(2)
        ->and($source)->toContain('Удалить категорию из формы?')
        ->and($source)->toContain('Удалить вопрос из формы?');
});
