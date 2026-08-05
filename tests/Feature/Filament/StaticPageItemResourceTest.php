<?php

use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Filament\Resources\StaticPageItems\Pages\EditStaticPageItem;
use App\Filament\Resources\StaticPageItems\Pages\ListStaticPageItems;
use App\Filament\Resources\StaticPageItems\StaticPageItemResource;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use App\Models\User;
use App\Policies\StaticPageItemPolicy;
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

test('static page item resource is fixed filtered and eager loads section page', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $item = StaticPageItem::query()->firstOrFail();
    $loaded = StaticPageItemResource::getEloquentQuery()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(StaticPageItemResource::class)
        ->and(StaticPageItemResource::getNavigationGroup())->toBe('Контент сайта')
        ->and(StaticPageItemResource::getNavigationLabel())->toBe('Элементы страниц')
        ->and(StaticPageItemResource::getGloballySearchableAttributes())->toBe(['title', 'label', 'code'])
        ->and(array_keys(StaticPageItemResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(StaticPageItem::class))->toBeInstanceOf(StaticPageItemPolicy::class)
        ->and($loaded->relationLoaded('section'))->toBeTrue()
        ->and($loaded->section->relationLoaded('page'))->toBeTrue();

    Livewire::test(ListStaticPageItems::class)
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('section.page.title')
        ->assertTableColumnExists('section.display_title')
        ->assertTableColumnExists('is_active')
        ->assertTableFilterExists('page')
        ->assertTableFilterExists('static_page_section_id')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $item)
        ->assertTableBulkActionDoesNotExist('delete');
    Livewire::test(EditStaticPageItem::class, ['record' => $item->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('item list query uses a fixed three-query nested eager loading plan', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    DB::flushQueryLog();
    DB::enableQueryLog();

    $records = StaticPageItemResource::getEloquentQuery()->get();
    foreach ($records as $item) {
        $item->section->page->title;
    }
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($records)->toHaveCount(24)
        ->and($queryCount)->toBe(3);
});

test('section filter options and item record titles never use blank labels', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $section = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutMetrics)->firstOrFail();
    $section->forceFill(['title' => null, 'label' => null])->save();

    $options = StaticPageItemResource::sectionOptions();
    expect($options[$section->getKey()])->toBe(StaticPageSectionCode::AboutMetrics->label())
        ->and($options)->not->toContain(null, '');

    $item = StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricParts)->firstOrFail();
    $item->forceFill(['title' => null, 'label' => 'Количество деталей'])->save();
    expect($item->refresh()->display_title)->toBe('Количество деталей')
        ->and(StaticPageItemResource::getRecordTitle($item))->toBe('Количество деталей');

    $item->forceFill(['title' => null, 'label' => null])->save();
    expect($item->refresh()->display_title)->toBe(StaticPageItemCode::AboutMetricParts->label())
        ->and(StaticPageItemResource::getRecordTitle($item))->toBe(StaticPageItemCode::AboutMetricParts->label());
});

test('item edit and toggle work while forged code parent and html are rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $item = StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricParts)->firstOrFail();
    $originalSectionId = $item->static_page_section_id;

    Livewire::test(EditStaticPageItem::class, ['record' => $item->getKey()])
        ->fillForm(['title' => '200 000+ деталей', 'text' => 'Обновлённый текст', 'position' => 15, 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($item->refresh()->title)->toBe('200 000+ деталей');

    Livewire::test(EditStaticPageItem::class, ['record' => $item->getKey()])
        ->set('data.code', StaticPageItemCode::HowStepChoose->value)
        ->call('save')
        ->assertHasFormErrors(['code']);
    Livewire::test(EditStaticPageItem::class, ['record' => $item->getKey()])
        ->set('data.static_page_section_id', StaticPageSection::query()->where('code', StaticPageSectionCode::HowSteps)->value('id'))
        ->call('save')
        ->assertHasFormErrors(['static_page_section_id']);
    Livewire::test(EditStaticPageItem::class, ['record' => $item->getKey()])
        ->set('data.text', '<b>bad</b>')
        ->call('save')
        ->assertHasFormErrors(['text']);
    expect($item->refresh()->code)->toBe(StaticPageItemCode::AboutMetricParts)
        ->and($item->static_page_section_id)->toBe($originalSectionId);

    Livewire::test(ListStaticPageItems::class)->callTableAction('toggle_active', $item);
    expect($item->refresh()->is_active)->toBeFalse();
});

test('manager has view only access to static page items', function (): void {
    $item = StaticPageItem::query()->firstOrFail();
    $before = $item->getAttributes();
    $this->actingAs(User::factory()->manager()->create());

    $this->get(StaticPageItemResource::getUrl('index'))->assertOk();
    $this->get(StaticPageItemResource::getUrl('view', ['record' => $item]))->assertOk();
    expect($this->get(StaticPageItemResource::getUrl('edit', ['record' => $item]))->getStatusCode())->not->toBe(200);
    Livewire::test(ListStaticPageItems::class)
        ->assertTableActionVisible('view', $item)
        ->assertTableActionHidden('edit', $item)
        ->assertTableActionHidden('toggle_active', $item);
    expect($item->fresh()->getAttributes())->toEqual($before);
});
