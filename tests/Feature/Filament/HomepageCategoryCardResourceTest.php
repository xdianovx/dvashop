<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\NavigationLinkType;
use App\Filament\Resources\HomepageCategoryCards\HomepageCategoryCardResource;
use App\Filament\Resources\HomepageCategoryCards\Pages\EditHomepageCategoryCard;
use App\Filament\Resources\HomepageCategoryCards\Pages\ListHomepageCategoryCards;
use App\Models\HomepageCategoryCard;
use App\Models\User;
use App\Policies\HomepageCategoryCardPolicy;
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

test('homepage category card resource is registered without create or delete operations', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $card = HomepageCategoryCard::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(HomepageCategoryCardResource::class)
        ->and(HomepageCategoryCardResource::getNavigationGroup())->toBe('Главная страница')
        ->and(HomepageCategoryCardResource::getNavigationLabel())->toBe('Категории')
        ->and(array_keys(HomepageCategoryCardResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(HomepageCategoryCard::class))->toBeInstanceOf(HomepageCategoryCardPolicy::class);

    Livewire::test(ListHomepageCategoryCards::class)
        ->assertCanSeeTableRecords(HomepageCategoryCard::query()->ordered()->get())
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('link_type')
        ->assertTableColumnExists('destination')
        ->assertTableColumnExists('open_in_new_tab')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $card)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditHomepageCategoryCard::class, ['record' => $card->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('homepage category card safely edits destinations toggles and reorders', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->firstOrFail();

    Livewire::test(EditHomepageCategoryCard::class, ['record' => $card->getKey()])
        ->fillForm([
            'title' => 'Коммерческие автомобили',
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => 'catalog.index',
            'url' => null,
            'open_in_new_tab' => false,
            'position' => 15,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($card->refresh()->title)->toBe('Коммерческие автомобили')
        ->and($card->link_type)->toBe(NavigationLinkType::Route)
        ->and($card->route_name)->toBe('catalog.index')
        ->and($card->url)->toBeNull();

    Livewire::test(ListHomepageCategoryCards::class)->callTableAction('toggle_active', $card);
    expect($card->refresh()->is_active)->toBeFalse();

    $ids = HomepageCategoryCard::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListHomepageCategoryCards::class)->call('reorderTable', $ids)->assertHasNoErrors();
    expect(HomepageCategoryCard::query()->ordered()->pluck('id')->all())->toBe($ids);
});

test('homepage category card forged mutations and manager writes are denied atomically', function (): void {
    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Commercial)->firstOrFail();
    $before = $card->getAttributes();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditHomepageCategoryCard::class, ['record' => $card->getKey()])
        ->set('data.code', HomepageCategoryCardCode::Sills->value)
        ->call('save')
        ->assertHasFormErrors(['code']);
    expect($card->fresh()->getAttributes())->toEqual($before);

    Livewire::test(EditHomepageCategoryCard::class, ['record' => $card->getKey()])
        ->fillForm([
            'title' => 'Несуществующий маршрут',
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => 'catalog.missing',
            'url' => null,
            'open_in_new_tab' => false,
            'position' => 10,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasFormErrors(['route_name']);
    expect($card->fresh()->getAttributes())->toEqual($before);

    $this->actingAs(User::factory()->manager()->create());
    $this->get(HomepageCategoryCardResource::getUrl('index'))->assertOk();
    $this->get(HomepageCategoryCardResource::getUrl('view', ['record' => $card]))->assertOk();
    expect($this->get(HomepageCategoryCardResource::getUrl('edit', ['record' => $card]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListHomepageCategoryCards::class)
        ->assertTableActionVisible('view', $card)
        ->assertTableActionHidden('edit', $card)
        ->assertTableActionHidden('toggle_active', $card);
    Livewire::test(ListHomepageCategoryCards::class)
        ->call('reorderTable', HomepageCategoryCard::query()->pluck('id')->all())
        ->assertForbidden();
    expect($card->fresh()->getAttributes())->toEqual($before);
});
