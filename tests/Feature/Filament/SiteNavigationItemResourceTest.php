<?php

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use App\Filament\Resources\SiteNavigationItems\Pages\CreateSiteNavigationItem;
use App\Filament\Resources\SiteNavigationItems\Pages\EditSiteNavigationItem;
use App\Filament\Resources\SiteNavigationItems\Pages\ListSiteNavigationItems;
use App\Filament\Resources\SiteNavigationItems\SiteNavigationItemResource;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Policies\SiteNavigationItemPolicy;
use App\Services\Settings\SiteNavigationAdminService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function navigationResourceData(string $code): array
{
    return [
        'code' => $code,
        'zone' => NavigationZone::HeaderTop->value,
        'title' => 'Партнёрам',
        'link_type' => NavigationLinkType::Route->value,
        'route_name' => 'partners',
        'url' => null,
        'open_in_new_tab' => false,
        'is_active' => true,
        'position' => 10,
    ];
}

test('site navigation resource is registered with required columns filters and policy', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(Filament::getPanel('admin')->getResources())->toContain(SiteNavigationItemResource::class)
        ->and(SiteNavigationItemResource::getNavigationGroup())->toBe('Настройки')
        ->and(SiteNavigationItemResource::getNavigationLabel())->toBe('Навигация сайта')
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(SiteNavigationItem::class))
        ->toBeInstanceOf(SiteNavigationItemPolicy::class);

    Livewire::test(ListSiteNavigationItems::class)
        ->assertTableColumnExists('zone')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('link_type')
        ->assertTableColumnExists('destination')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('zone')
        ->assertTableFilterExists('is_active')
        ->assertTableFilterExists('link_type');
});

test('admin creates updates activates and deletes navigation through service backed filament actions', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateSiteNavigationItem::class)
        ->fillForm(navigationResourceData('partners-link'))
        ->call('create')
        ->assertHasNoFormErrors();

    $item = SiteNavigationItem::query()->where('code', 'partners-link')->firstOrFail();

    Livewire::test(EditSiteNavigationItem::class, ['record' => $item->getKey()])
        ->fillForm([
            'title' => 'Наш Telegram',
            'link_type' => NavigationLinkType::Url->value,
            'route_name' => null,
            'url' => 'https://t.me/magazporogi',
            'open_in_new_tab' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->refresh()->title)->toBe('Наш Telegram')
        ->and($item->link_type)->toBe(NavigationLinkType::Url)
        ->and($item->route_name)->toBeNull();

    Livewire::test(ListSiteNavigationItems::class)
        ->callTableAction('toggle_active', $item)
        ->assertHasNoTableActionErrors();
    expect($item->refresh()->is_active)->toBeFalse();

    Livewire::test(ListSiteNavigationItems::class)
        ->callTableAction('delete', $item)
        ->assertHasNoTableActionErrors();
    expect(SiteNavigationItem::query()->whereKey($item)->exists())->toBeFalse();
});

test('duplicate code and unsafe livewire destinations are validation errors without writes', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    SiteNavigationItem::factory()->create(['code' => 'duplicate-code']);

    Livewire::test(CreateSiteNavigationItem::class)
        ->fillForm(navigationResourceData('duplicate-code'))
        ->call('create')
        ->assertStatus(200)
        ->assertHasFormErrors(['code']);

    Livewire::test(CreateSiteNavigationItem::class)
        ->fillForm([
            ...navigationResourceData('unsafe-link'),
            'link_type' => NavigationLinkType::Url->value,
            'route_name' => null,
            'url' => 'javascript:alert(1)',
        ])
        ->call('create')
        ->assertStatus(200)
        ->assertHasFormErrors(['url']);

    expect(SiteNavigationItem::query()->count())->toBe(1);
});

test('manager navigation access is view only including forged livewire actions', function (): void {
    $item = SiteNavigationItem::factory()->create();
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $this->get(SiteNavigationItemResource::getUrl('index'))->assertOk();
    $this->get(SiteNavigationItemResource::getUrl('view', ['record' => $item]))->assertOk();

    expect($this->get(SiteNavigationItemResource::getUrl('create'))->getStatusCode())->not->toBe(200)
        ->and($this->get(SiteNavigationItemResource::getUrl('edit', ['record' => $item]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListSiteNavigationItems::class)
        ->assertTableActionVisible('view', $item)
        ->assertTableActionHidden('edit', $item)
        ->assertTableActionHidden('toggle_active', $item)
        ->assertTableActionHidden('delete', $item);

    expect(fn () => app(SiteNavigationAdminService::class)->setActive($manager, $item, false))
        ->toThrow(AuthorizationException::class);

    expect($item->refresh()->is_active)->toBeTrue();
});

test('filament reorder is enabled only for one selected zone and rejects forged cross zone ids', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $first = SiteNavigationItem::factory()->create([
        'code' => 'first-header',
        'zone' => NavigationZone::HeaderTop,
        'position' => 10,
    ]);
    $second = SiteNavigationItem::factory()->create([
        'code' => 'second-header',
        'zone' => NavigationZone::HeaderTop,
        'position' => 20,
    ]);
    $footer = SiteNavigationItem::factory()->create([
        'code' => 'footer-item',
        'zone' => NavigationZone::FooterAbout,
        'position' => 10,
    ]);

    $component = Livewire::test(ListSiteNavigationItems::class)
        ->filterTable('zone', NavigationZone::HeaderTop->value);

    expect($component->instance()->mayReorderCurrentZone())->toBeTrue();

    $component->call('reorderTable', [$second->getKey(), $first->getKey()])
        ->assertHasNoErrors();

    expect($second->refresh()->position)->toBe(0)
        ->and($first->refresh()->position)->toBe(1);

    $component->call('reorderTable', [$first->getKey(), $footer->getKey()])
        ->assertStatus(200)
        ->assertHasErrors(['ids']);
});
