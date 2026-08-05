<?php

use App\Enums\HomepageQuickLinkCode;
use App\Enums\NavigationLinkType;
use App\Filament\Resources\HomepageQuickLinks\HomepageQuickLinkResource;
use App\Filament\Resources\HomepageQuickLinks\Pages\EditHomepageQuickLink;
use App\Filament\Resources\HomepageQuickLinks\Pages\ListHomepageQuickLinks;
use App\Models\HomepageQuickLink;
use App\Models\User;
use App\Policies\HomepageQuickLinkPolicy;
use Database\Seeders\HomepageContentSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(HomepageContentSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('homepage quick link resource is registered without create or delete operations', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $link = HomepageQuickLink::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(HomepageQuickLinkResource::class)
        ->and(HomepageQuickLinkResource::getNavigationGroup())->toBe('Главная страница')
        ->and(HomepageQuickLinkResource::getNavigationLabel())->toBe('Быстрые ссылки')
        ->and(array_keys(HomepageQuickLinkResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(HomepageQuickLink::class))->toBeInstanceOf(HomepageQuickLinkPolicy::class);

    Livewire::test(ListHomepageQuickLinks::class)
        ->assertCanSeeTableRecords(HomepageQuickLink::query()->ordered()->get())
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('link_type')
        ->assertTableColumnExists('destination')
        ->assertTableColumnExists('open_in_new_tab')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $link)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditHomepageQuickLink::class, ['record' => $link->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('homepage quick link safely edits destinations toggles and reorders', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $link = HomepageQuickLink::query()->where('code', HomepageQuickLinkCode::Promotions)->firstOrFail();

    Livewire::test(EditHomepageQuickLink::class, ['record' => $link->getKey()])
        ->fillForm([
            'title' => 'Спецпредложения',
            'link_type' => NavigationLinkType::Url->value,
            'route_name' => null,
            'url' => 'https://example.com/promotions',
            'open_in_new_tab' => true,
            'position' => 15,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($link->refresh()->title)->toBe('Спецпредложения')
        ->and($link->link_type)->toBe(NavigationLinkType::Url)
        ->and($link->route_name)->toBeNull()
        ->and($link->url)->toBe('https://example.com/promotions')
        ->and($link->open_in_new_tab)->toBeTrue();

    Livewire::test(ListHomepageQuickLinks::class)->callTableAction('toggle_active', $link);
    expect($link->refresh()->is_active)->toBeFalse();

    $ids = HomepageQuickLink::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListHomepageQuickLinks::class)->call('reorderTable', $ids)->assertHasNoErrors();
    expect(HomepageQuickLink::query()->ordered()->pluck('id')->all())->toBe($ids);
});

test('homepage quick link forged mutations and manager writes are denied atomically', function (): void {
    $link = HomepageQuickLink::query()->where('code', HomepageQuickLinkCode::Promotions)->firstOrFail();
    $before = $link->getAttributes();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditHomepageQuickLink::class, ['record' => $link->getKey()])
        ->set('data.code', HomepageQuickLinkCode::Reviews->value)
        ->call('save')
        ->assertHasFormErrors(['code']);
    expect($link->fresh()->getAttributes())->toEqual($before);

    Livewire::test(EditHomepageQuickLink::class, ['record' => $link->getKey()])
        ->fillForm([
            'title' => 'Опасная ссылка',
            'link_type' => NavigationLinkType::Url->value,
            'route_name' => null,
            'url' => 'javascript:alert(1)',
            'open_in_new_tab' => false,
            'position' => 10,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasFormErrors(['url']);
    expect($link->fresh()->getAttributes())->toEqual($before);

    $this->actingAs(User::factory()->manager()->create());
    $this->get(HomepageQuickLinkResource::getUrl('index'))->assertOk();
    $this->get(HomepageQuickLinkResource::getUrl('view', ['record' => $link]))->assertOk();
    expect($this->get(HomepageQuickLinkResource::getUrl('edit', ['record' => $link]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListHomepageQuickLinks::class)
        ->assertTableActionVisible('view', $link)
        ->assertTableActionHidden('edit', $link)
        ->assertTableActionHidden('toggle_active', $link);
    Livewire::test(ListHomepageQuickLinks::class)
        ->call('reorderTable', HomepageQuickLink::query()->pluck('id')->all())
        ->assertForbidden();
    expect($link->fresh()->getAttributes())->toEqual($before);
});
