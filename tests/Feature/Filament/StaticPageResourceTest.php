<?php

use App\Enums\StaticPageCode;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Models\StaticPage;
use App\Models\User;
use App\Policies\StaticPagePolicy;
use Database\Seeders\StaticPageContentSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(StaticPageContentSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('static page resource is registered as fixed content with expected navigation and table', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $page = StaticPage::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(StaticPageResource::class)
        ->and(StaticPageResource::getNavigationGroup())->toBe('Контент сайта')
        ->and(StaticPageResource::getNavigationLabel())->toBe('Страницы')
        ->and(array_keys(StaticPageResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(StaticPage::class))->toBeInstanceOf(StaticPagePolicy::class);

    Livewire::test(ListStaticPages::class)
        ->assertCanSeeTableRecords(StaticPage::query()->ordered()->get())
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $page)
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(EditStaticPage::class, ['record' => $page->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('static page edit toggle and reorder use service while forged code is rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $page = StaticPage::query()->where('code', StaticPageCode::About)->firstOrFail();

    Livewire::test(EditStaticPage::class, ['record' => $page->getKey()])
        ->fillForm(['title' => 'О компании', 'subtitle' => 'Новый текст', 'position' => 15, 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($page->refresh()->title)->toBe('О компании')->and($page->subtitle)->toBe('Новый текст');

    Livewire::test(EditStaticPage::class, ['record' => $page->getKey()])
        ->set('data.code', StaticPageCode::How->value)
        ->call('save')
        ->assertHasFormErrors(['code']);
    expect($page->refresh()->code)->toBe(StaticPageCode::About);

    Livewire::test(ListStaticPages::class)->callTableAction('toggle_active', $page);
    expect($page->refresh()->is_active)->toBeFalse();

    $ids = StaticPage::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListStaticPages::class)->call('reorderTable', $ids)->assertHasNoErrors();
    expect(StaticPage::query()->ordered()->pluck('id')->all())->toBe($ids);
});

test('manager can only list and view static pages', function (): void {
    $page = StaticPage::query()->firstOrFail();
    $before = $page->getAttributes();
    $this->actingAs(User::factory()->manager()->create());

    $this->get(StaticPageResource::getUrl('index'))->assertOk();
    $this->get(StaticPageResource::getUrl('view', ['record' => $page]))->assertOk();
    expect($this->get(StaticPageResource::getUrl('edit', ['record' => $page]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListStaticPages::class)
        ->assertTableActionVisible('view', $page)
        ->assertTableActionHidden('edit', $page)
        ->assertTableActionHidden('toggle_active', $page)
        ->call('reorderTable', StaticPage::query()->pluck('id')->all())
        ->assertForbidden();
    expect($page->fresh()->getAttributes())->toEqual($before);
});
