<?php

use App\Filament\Resources\FaqCategories\FaqCategoryResource;
use App\Filament\Resources\FaqCategories\Pages\CreateFaqCategory;
use App\Filament\Resources\FaqCategories\Pages\EditFaqCategory;
use App\Filament\Resources\FaqCategories\Pages\ListFaqCategories;
use App\Models\FaqCategory;
use App\Models\User;
use App\Policies\FaqCategoryPolicy;
use Database\Seeders\FaqSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FaqSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('faq category resource is registered with filters service actions and no force delete', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $category = FaqCategory::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(FaqCategoryResource::class)
        ->and(FaqCategoryResource::getNavigationGroup())->toBe('Контент сайта')
        ->and(FaqCategoryResource::getNavigationLabel())->toBe('Категории FAQ')
        ->and(array_keys(FaqCategoryResource::getPages()))->toBe(['index', 'create', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(FaqCategory::class))->toBeInstanceOf(FaqCategoryPolicy::class);

    Livewire::test(ListFaqCategories::class)
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('items_count')
        ->assertTableColumnExists('is_active')
        ->assertTableFilterExists('is_active')
        ->assertTableFilterExists('trashed')
        ->assertTableActionExists('delete', record: $category)
        ->assertTableActionExists('restore', record: $category)
        ->assertTableActionDoesNotExist('forceDelete', record: $category)
        ->assertTableBulkActionDoesNotExist('forceDelete');
    Livewire::test(EditFaqCategory::class, ['record' => $category->getKey()])
        ->assertActionDoesNotExist(ForceDeleteAction::class);
});

test('faq category list query keeps item counts in one query', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    DB::flushQueryLog();
    DB::enableQueryLog();

    $records = FaqCategoryResource::getEloquentQuery()->get();
    foreach ($records as $category) {
        $category->items_count;
    }
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($records)->toHaveCount(6)
        ->and($queryCount)->toBe(1);
});

test('faq category create edit toggle reorder delete guard and restore use service layer', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateFaqCategory::class)
        ->fillForm(['title' => 'Новая категория', 'position' => 70, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    $category = FaqCategory::query()->where('title', 'Новая категория')->firstOrFail();
    expect($category->code)->toStartWith('faq_category_');

    Livewire::test(EditFaqCategory::class, ['record' => $category->getKey()])
        ->fillForm(['title' => 'Обновлённая категория', 'position' => 71, 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($category->refresh()->title)->toBe('Обновлённая категория');

    Livewire::test(CreateFaqCategory::class)
        ->fillForm(['title' => '<b>HTML</b>', 'position' => 0, 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['title']);

    Livewire::test(ListFaqCategories::class)->callTableAction('toggle_active', $category);
    expect($category->refresh()->is_active)->toBeFalse();

    $ids = FaqCategory::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListFaqCategories::class)->call('reorderTable', $ids)->assertHasNoErrors();
    expect(FaqCategory::query()->ordered()->pluck('id')->all())->toBe($ids);

    $withItems = FaqCategory::query()->where('code', 'common')->firstOrFail();
    Livewire::test(ListFaqCategories::class)
        ->callTableAction('delete', $withItems)
        ->assertHasErrors();
    expect($withItems->fresh())->not->toBeNull();

    Livewire::test(ListFaqCategories::class)
        ->callTableAction('delete', $category)
        ->assertNotified(Notification::make()->success()->title('Категория FAQ удалена'))
        ->assertNotNotified('Не удалось удалить категорию FAQ')
        ->assertHasNoErrors();
    $trashed = FaqCategory::withTrashed()->findOrFail($category->getKey());
    expect($trashed->trashed())->toBeTrue();
    Livewire::test(ListFaqCategories::class)->callTableAction('restore', $trashed);
    expect($category->fresh())->not->toBeNull();
});

test('faq category edit delete action soft deletes reports success and does not report failure', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $category = FaqCategory::factory()->create([
        'title' => 'Удаляемая категория',
        'position' => 999,
        'is_active' => true,
    ]);

    Livewire::test(EditFaqCategory::class, ['record' => $category->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified(Notification::make()->success()->title('Категория FAQ удалена'))
        ->assertNotNotified('Не удалось удалить категорию FAQ')
        ->assertHasNoErrors();

    expect(FaqCategory::withTrashed()->findOrFail($category->getKey())->trashed())->toBeTrue();
});

test('manager can view faq categories but cannot create edit toggle delete restore or reorder', function (): void {
    $category = FaqCategory::query()->firstOrFail();
    $before = $category->getAttributes();
    $this->actingAs(User::factory()->manager()->create());

    $this->get(FaqCategoryResource::getUrl('index'))->assertOk();
    $this->get(FaqCategoryResource::getUrl('view', ['record' => $category]))->assertOk();
    expect($this->get(FaqCategoryResource::getUrl('create'))->getStatusCode())->not->toBe(200)
        ->and($this->get(FaqCategoryResource::getUrl('edit', ['record' => $category]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListFaqCategories::class)
        ->assertTableActionVisible('view', $category)
        ->assertTableActionHidden('edit', $category)
        ->assertTableActionHidden('toggle_active', $category)
        ->assertTableActionHidden('delete', $category)
        ->call('reorderTable', FaqCategory::query()->pluck('id')->all())
        ->assertForbidden();
    expect($category->fresh()->getAttributes())->toEqual($before);
});
