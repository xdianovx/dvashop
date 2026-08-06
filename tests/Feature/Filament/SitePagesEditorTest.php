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
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'section' => 'Быстрые ссылки',
            'read_only_field' => 'quick_links',
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
    'homepage' => [EditHomepagePage::class, ['Секции главной страницы', 'Название секции', 'Быстрые ссылки', 'Куда ведёт ссылка', 'Витринные категории', 'Префикс', 'Значение', 'Суффикс']],
    'about' => [EditAboutPage::class, ['Первый экран', 'Показатели', 'Технологии точности', 'Наша цель']],
    'how' => [EditHowPage::class, ['Шесть шагов', 'Название', 'Описание']],
    'payment' => [EditPaymentPage::class, ['Способы оплаты', 'Способы доставки', 'Базовая стоимость']],
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

test('forged fixed structure field is reported on the page and nothing is changed', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $service = app(SitePageContentAdminService::class);
    $before = $service->homepageState();

    Livewire::test(EditHomepagePage::class)
        ->set('data.quick_links.0.code', 'forged')
        ->call('save')
        ->assertHasErrors(['data.quick_links.0.code']);

    expect($service->homepageState())->toBe($before);
});

test('homepage editor contains four fixed sections in system order and privileged roles can update only title and activity', function (string $role): void {
    $actor = $role === 'super_admin'
        ? User::factory()->superAdmin()->create()
        : User::factory()->admin()->create();
    $this->actingAs($actor);
    $service = app(SitePageContentAdminService::class);
    $beforeStructure = HomepageSection::query()->ordered()->get(['id', 'code', 'position'])->toArray();
    $state = $service->homepageState();

    expect($state['sections'])->toHaveCount(4)
        ->and(array_column($state['sections'], 'id'))->toBe(array_column($beforeStructure, 'id'))
        ->and(HomepageSection::query()->ordered()->get()->map(
            fn (HomepageSection $section): string => $section->code->value,
        )->all())->toBe([
            HomepageSectionCode::QuickLinks->value,
            HomepageSectionCode::VehicleSearch->value,
            HomepageSectionCode::CategoryCards->value,
            HomepageSectionCode::AboutMetrics->value,
        ]);

    foreach ($state['sections'] as $index => $section) {
        $state['sections'][$index]['title'] = "{$role}: секция ".($index + 1);
        $state['sections'][$index]['is_active'] = $index !== 1;
    }

    Livewire::test(EditHomepagePage::class)
        ->set('data.sections', $state['sections'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotified('Главная страница сохранена');

    expect(HomepageSection::query()->ordered()->pluck('title')->all())->toBe([
        "{$role}: секция 1",
        "{$role}: секция 2",
        "{$role}: секция 3",
        "{$role}: секция 4",
    ])->and(HomepageSection::query()->ordered()->get()->map(
        fn (HomepageSection $section): bool => $section->is_active,
    )->all())->toBe([true, false, true, true])
        ->and(HomepageSection::query()->ordered()->get(['id', 'code', 'position'])->toArray())->toBe($beforeStructure);
})->with(['super admin' => ['super_admin'], 'admin' => ['admin']]);

test('manager sees homepage sections read only and cannot forge a save', function (): void {
    $this->actingAs(User::factory()->manager()->create());
    $before = app(SitePageContentAdminService::class)->homepageState();

    Livewire::test(EditHomepagePage::class)
        ->assertFormFieldDisabled('sections')
        ->set('data.sections.0.title', 'Поддельное изменение')
        ->call('save')
        ->assertForbidden();

    expect(app(SitePageContentAdminService::class)->homepageState())->toBe($before);
});

test('homepage form rejects a fifth section an omitted section and forged structural fields', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $service = app(SitePageContentAdminService::class);
    $before = $service->homepageState();

    $withFifth = $before['sections'];
    $withFifth[] = [
        'id' => 999999,
        'title' => 'Чужая секция',
        'is_active' => true,
    ];
    Livewire::test(EditHomepagePage::class)
        ->set('data.sections', $withFifth)
        ->call('save')
        ->assertHasErrors(['data.sections.4.id']);

    Livewire::test(EditHomepagePage::class)
        ->set('data.sections', array_slice($before['sections'], 0, 3))
        ->call('save')
        ->assertHasErrors(['data.sections']);

    foreach (['code', 'position'] as $field) {
        Livewire::test(EditHomepagePage::class)
            ->set("data.sections.0.{$field}", $field === 'code' ? 'forged' : 999)
            ->call('save')
            ->assertHasErrors(["data.sections.0.{$field}"]);
    }

    expect($service->homepageState())->toBe($before);
});

test('faq repeater requires confirmation before removing categories and questions from the form', function (): void {
    $source = file_get_contents(app_path('Filament/Pages/SiteContent/EditFaqPage.php'));

    expect($source)->not->toBeFalse()
        ->and(substr_count((string) $source, '->deleteAction('))->toBe(2)
        ->and(substr_count((string) $source, '->requiresConfirmation()'))->toBe(2)
        ->and($source)->toContain('Удалить категорию из формы?')
        ->and($source)->toContain('Удалить вопрос из формы?');
});
