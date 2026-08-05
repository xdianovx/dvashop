<?php

use App\Enums\HomepageSectionCode;
use App\Filament\Resources\HomepageSections\HomepageSectionResource;
use App\Filament\Resources\HomepageSections\Pages\EditHomepageSection;
use App\Filament\Resources\HomepageSections\Pages\ListHomepageSections;
use App\Models\HomepageSection;
use App\Models\User;
use App\Policies\HomepageSectionPolicy;
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

test('homepage section resource is registered without create or delete operations', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $section = HomepageSection::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(HomepageSectionResource::class)
        ->and(HomepageSectionResource::getNavigationGroup())->toBe('Главная страница')
        ->and(HomepageSectionResource::getNavigationLabel())->toBe('Секции')
        ->and(array_keys(HomepageSectionResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(HomepageSection::class))->toBeInstanceOf(HomepageSectionPolicy::class);

    Livewire::test(ListHomepageSections::class)
        ->assertCanSeeTableRecords(HomepageSection::query()->ordered()->get())
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $section)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditHomepageSection::class, ['record' => $section->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('homepage section edits toggles and reorders through the aggregate service', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $section = HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->firstOrFail();

    Livewire::test(EditHomepageSection::class, ['record' => $section->getKey()])
        ->fillForm(['title' => 'Новый заголовок', 'position' => 15, 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($section->refresh()->title)->toBe('Новый заголовок');

    Livewire::test(ListHomepageSections::class)->callTableAction('toggle_active', $section);
    expect($section->refresh()->is_active)->toBeFalse();

    $ids = HomepageSection::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListHomepageSections::class)->call('reorderTable', $ids)->assertHasNoErrors();
    expect(HomepageSection::query()->ordered()->pluck('id')->all())->toBe($ids);
});

test('homepage section forged livewire mutation and manager writes are denied', function (): void {
    $section = HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->firstOrFail();
    $before = $section->getAttributes();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditHomepageSection::class, ['record' => $section->getKey()])
        ->set('data.code', HomepageSectionCode::QuickLinks->value)
        ->call('save')
        ->assertHasFormErrors(['code']);
    expect($section->fresh()->getAttributes())->toEqual($before);

    $this->actingAs(User::factory()->manager()->create());
    $this->get(HomepageSectionResource::getUrl('index'))->assertOk();
    $this->get(HomepageSectionResource::getUrl('view', ['record' => $section]))->assertOk();
    expect($this->get(HomepageSectionResource::getUrl('edit', ['record' => $section]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListHomepageSections::class)
        ->assertTableActionVisible('view', $section)
        ->assertTableActionHidden('edit', $section)
        ->assertTableActionHidden('toggle_active', $section);
    Livewire::test(ListHomepageSections::class)
        ->call('reorderTable', HomepageSection::query()->pluck('id')->all())
        ->assertForbidden();
    expect($section->fresh()->getAttributes())->toEqual($before);
});
