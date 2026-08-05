<?php

use App\Enums\StaticPageCode;
use App\Enums\StaticPageSectionCode;
use App\Filament\Resources\StaticPageSections\Pages\EditStaticPageSection;
use App\Filament\Resources\StaticPageSections\Pages\ListStaticPageSections;
use App\Filament\Resources\StaticPageSections\StaticPageSectionResource;
use App\Models\StaticPage;
use App\Models\StaticPageSection;
use App\Models\User;
use App\Policies\StaticPageSectionPolicy;
use Database\Seeders\StaticPageContentSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(StaticPageContentSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('static page section resource is fixed filtered and eager loads page', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $section = StaticPageSection::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(StaticPageSectionResource::class)
        ->and(StaticPageSectionResource::getNavigationGroup())->toBe('Контент сайта')
        ->and(StaticPageSectionResource::getNavigationLabel())->toBe('Блоки страниц')
        ->and(StaticPageSectionResource::getGloballySearchableAttributes())->toBe(['title', 'label', 'code'])
        ->and(array_keys(StaticPageSectionResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(StaticPageSection::class))->toBeInstanceOf(StaticPageSectionPolicy::class)
        ->and(StaticPageSectionResource::getEloquentQuery()->firstOrFail()->relationLoaded('page'))->toBeTrue();

    Livewire::test(ListStaticPageSections::class)
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('page.title')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('is_active')
        ->assertTableFilterExists('static_page_id')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $section)
        ->assertTableBulkActionDoesNotExist('delete');
    Livewire::test(EditStaticPageSection::class, ['record' => $section->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('section list query uses a fixed two-query eager loading plan', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    DB::flushQueryLog();
    DB::enableQueryLog();

    $records = StaticPageSectionResource::getEloquentQuery()->get();
    foreach ($records as $section) {
        $section->page->title;
    }
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($records)->toHaveCount(8)
        ->and($queryCount)->toBe(2);
});

test('section record title falls back from title to label enum label and code', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $section = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutMetrics)->firstOrFail();

    $section->forceFill(['title' => null, 'label' => 'Показатели'])->save();
    expect($section->refresh()->display_title)->toBe('Показатели')
        ->and(StaticPageSectionResource::getRecordTitle($section))->toBe('Показатели');

    $section->forceFill(['title' => null, 'label' => null])->save();
    expect($section->refresh()->display_title)->toBe(StaticPageSectionCode::AboutMetrics->label())
        ->and(StaticPageSectionResource::getRecordTitle($section))->toBe(StaticPageSectionCode::AboutMetrics->label());
});

test('section edit and toggle work while forged code and parent are rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $section = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutHero)->firstOrFail();
    $originalPageId = $section->static_page_id;

    Livewire::test(EditStaticPageSection::class, ['record' => $section->getKey()])
        ->fillForm(['label' => 'Компания', 'title' => 'Новый заголовок', 'body' => 'Новый текст', 'position' => 15, 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($section->refresh()->title)->toBe('Новый заголовок');

    Livewire::test(EditStaticPageSection::class, ['record' => $section->getKey()])
        ->set('data.code', StaticPageSectionCode::HowSteps->value)
        ->call('save')
        ->assertHasFormErrors(['code']);
    Livewire::test(EditStaticPageSection::class, ['record' => $section->getKey()])
        ->set('data.static_page_id', StaticPage::query()->where('code', StaticPageCode::How)->value('id'))
        ->call('save')
        ->assertHasFormErrors(['static_page_id']);
    expect($section->refresh()->code)->toBe(StaticPageSectionCode::AboutHero)
        ->and($section->static_page_id)->toBe($originalPageId);

    Livewire::test(ListStaticPageSections::class)->callTableAction('toggle_active', $section);
    expect($section->refresh()->is_active)->toBeFalse();
});

test('manager has view only access to static page sections', function (): void {
    $section = StaticPageSection::query()->firstOrFail();
    $before = $section->getAttributes();
    $this->actingAs(User::factory()->manager()->create());

    $this->get(StaticPageSectionResource::getUrl('index'))->assertOk();
    $this->get(StaticPageSectionResource::getUrl('view', ['record' => $section]))->assertOk();
    expect($this->get(StaticPageSectionResource::getUrl('edit', ['record' => $section]))->getStatusCode())->not->toBe(200);
    Livewire::test(ListStaticPageSections::class)
        ->assertTableActionVisible('view', $section)
        ->assertTableActionHidden('edit', $section)
        ->assertTableActionHidden('toggle_active', $section);
    expect($section->fresh()->getAttributes())->toEqual($before);
});
