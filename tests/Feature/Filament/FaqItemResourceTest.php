<?php

use App\Filament\Resources\FaqItems\FaqItemResource;
use App\Filament\Resources\FaqItems\Pages\CreateFaqItem;
use App\Filament\Resources\FaqItems\Pages\EditFaqItem;
use App\Filament\Resources\FaqItems\Pages\ListFaqItems;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\User;
use App\Policies\FaqItemPolicy;
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

test('faq item resource is registered filtered eager loaded and has no force delete', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $item = FaqItem::query()->firstOrFail();
    $loaded = FaqItemResource::getEloquentQuery()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(FaqItemResource::class)
        ->and(FaqItemResource::getNavigationGroup())->toBe('Контент сайта')
        ->and(FaqItemResource::getNavigationLabel())->toBe('Вопросы FAQ')
        ->and(array_keys(FaqItemResource::getPages()))->toBe(['index', 'create', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(FaqItem::class))->toBeInstanceOf(FaqItemPolicy::class)
        ->and($loaded->relationLoaded('category'))->toBeTrue();

    Livewire::test(ListFaqItems::class)
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('category.title')
        ->assertTableColumnExists('question')
        ->assertTableColumnExists('is_featured')
        ->assertTableColumnExists('is_active')
        ->assertTableFilterExists('faq_category_id')
        ->assertTableFilterExists('is_active')
        ->assertTableFilterExists('is_featured')
        ->assertTableFilterExists('trashed')
        ->assertTableActionDoesNotExist('forceDelete', record: $item)
        ->assertTableBulkActionDoesNotExist('forceDelete');
    Livewire::test(EditFaqItem::class, ['record' => $item->getKey()])
        ->assertActionDoesNotExist(ForceDeleteAction::class);
});

test('faq item list query uses a fixed two-query eager loading plan', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    DB::flushQueryLog();
    DB::enableQueryLog();

    $records = FaqItemResource::getEloquentQuery()->get();
    foreach ($records as $item) {
        $item->category->title;
    }
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($records)->toHaveCount(18)
        ->and($queryCount)->toBe(2);
});

test('faq item create edit move toggles delete restore and validation use service layer', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $source = FaqCategory::query()->where('code', 'products')->firstOrFail();
    $target = FaqCategory::query()->where('code', 'website')->firstOrFail();

    Livewire::test(CreateFaqItem::class)
        ->fillForm([
            'faq_category_id' => $source->getKey(),
            'question' => 'Новый вопрос?',
            'answer' => 'Новый ответ.',
            'position' => 40,
            'is_active' => true,
            'is_featured' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $item = FaqItem::query()->where('question', 'Новый вопрос?')->firstOrFail();
    expect($item->code)->toStartWith('faq_item_');

    Livewire::test(EditFaqItem::class, ['record' => $item->getKey()])
        ->fillForm([
            'faq_category_id' => $target->getKey(),
            'question' => 'Перенесённый вопрос?',
            'answer' => 'Перенесённый ответ.',
            'position' => 41,
            'is_active' => true,
            'is_featured' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($item->refresh()->faq_category_id)->toBe($target->getKey())
        ->and($item->question)->toBe('Перенесённый вопрос?');

    Livewire::test(EditFaqItem::class, ['record' => $item->getKey()])
        ->fillForm([
            'faq_category_id' => $target->getKey(),
            'question' => '<b>HTML?</b>',
            'answer' => '<script>bad</script>',
            'position' => 41,
            'is_active' => true,
            'is_featured' => false,
        ])
        ->call('save')
        ->assertHasFormErrors(['question', 'answer']);

    Livewire::test(ListFaqItems::class)->callTableAction('toggle_active', $item);
    expect($item->refresh()->is_active)->toBeFalse();
    Livewire::test(ListFaqItems::class)->callTableAction('toggle_featured', $item);
    expect($item->refresh()->is_featured)->toBeTrue();

    Livewire::test(ListFaqItems::class)
        ->callTableAction('delete', $item)
        ->assertNotified(Notification::make()->success()->title('Вопрос FAQ удалён'))
        ->assertNotNotified('Не удалось удалить вопрос FAQ')
        ->assertHasNoErrors();
    $trashed = FaqItem::withTrashed()->findOrFail($item->getKey());
    expect($trashed->trashed())->toBeTrue();
    Livewire::test(ListFaqItems::class)->callTableAction('restore', $trashed);
    expect($item->fresh())->not->toBeNull();
});

test('faq item edit delete action soft deletes reports success and does not report failure', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $item = FaqItem::query()->where('code', 'delivery_process')->firstOrFail();

    Livewire::test(EditFaqItem::class, ['record' => $item->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified(Notification::make()->success()->title('Вопрос FAQ удалён'))
        ->assertNotNotified('Не удалось удалить вопрос FAQ')
        ->assertHasNoErrors();

    expect(FaqItem::withTrashed()->findOrFail($item->getKey())->trashed())->toBeTrue();
});

test('manager can view faq items but write actions and routes are denied', function (): void {
    $item = FaqItem::query()->firstOrFail();
    $before = $item->getAttributes();
    $this->actingAs(User::factory()->manager()->create());

    $this->get(FaqItemResource::getUrl('index'))->assertOk();
    $this->get(FaqItemResource::getUrl('view', ['record' => $item]))->assertOk();
    expect($this->get(FaqItemResource::getUrl('create'))->getStatusCode())->not->toBe(200)
        ->and($this->get(FaqItemResource::getUrl('edit', ['record' => $item]))->getStatusCode())->not->toBe(200);

    Livewire::test(ListFaqItems::class)
        ->assertTableActionVisible('view', $item)
        ->assertTableActionHidden('edit', $item)
        ->assertTableActionHidden('toggle_active', $item)
        ->assertTableActionHidden('toggle_featured', $item)
        ->assertTableActionHidden('delete', $item);
    expect($item->fresh()->getAttributes())->toEqual($before);
});
