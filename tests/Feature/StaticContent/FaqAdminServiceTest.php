<?php

use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\User;
use App\Services\StaticContent\FaqAdminService;
use Database\Seeders\FaqSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FaqSeeder::class);
    $this->faqService = app(FaqAdminService::class);
    $this->faqAdmin = User::factory()->admin()->create();
});

test('admin and super admin create update toggle reorder and move faq records with generated codes', function (string $state): void {
    $actor = User::factory()->{$state}()->create();
    $category = $this->faqService->createCategory($actor, ['title' => '  Новая категория  ', 'position' => 70]);
    expect($category->title)->toBe('Новая категория')
        ->and($category->code)->toMatch('/\Afaq_category_[0-9a-z]{26}\z/')
        ->and($category->is_active)->toBeTrue();

    $item = $this->faqService->createItem($actor, $category, [
        'question' => '  Новый вопрос?  ',
        'answer' => '  Новый ответ.  ',
        'is_featured' => true,
        'position' => 5,
    ]);
    expect($item->question)->toBe('Новый вопрос?')
        ->and($item->answer)->toBe('Новый ответ.')
        ->and($item->code)->toMatch('/\Afaq_item_[0-9a-z]{26}\z/')
        ->and($item->is_featured)->toBeTrue()
        ->and($item->position)->toBe(5);

    $target = FaqCategory::query()->where('code', 'products')->firstOrFail();
    $moved = $this->faqService->updateItem($actor, $item, [
        'faq_category_id' => $target->getKey(),
        'question' => '  Перенесённый вопрос?  ',
        'answer' => '  Перенесённый ответ.  ',
        'is_active' => false,
        'is_featured' => false,
        'position' => 22,
    ]);
    expect($moved->faq_category_id)->toBe($target->getKey())
        ->and($moved->question)->toBe('Перенесённый вопрос?')
        ->and($moved->is_active)->toBeFalse()
        ->and($moved->is_featured)->toBeFalse()
        ->and($moved->position)->toBe(22);

    expect($this->faqService->setItemActive($actor, $moved, true)->is_active)->toBeTrue()
        ->and($this->faqService->setItemFeatured($actor, $moved, true)->is_featured)->toBeTrue()
        ->and($this->faqService->setCategoryActive($actor, $category, false)->is_active)->toBeFalse();

    $ids = FaqCategory::query()->ordered()->pluck('id')->reverse()->values()->all();
    $this->faqService->reorderCategories($actor, $ids);
    expect(FaqCategory::query()->ordered()->pluck('id')->all())->toBe($ids);
})->with(['admin', 'superAdmin']);

test('faq service rejects unexpected code html unsafe booleans deleted and missing categories atomically', function (): void {
    $category = FaqCategory::query()->where('code', 'products')->firstOrFail();
    $item = FaqItem::query()->where('code', 'products_availability')->firstOrFail();
    $target = $this->faqService->createCategory($this->faqAdmin, ['title' => 'Удалённая цель']);
    $this->faqService->deleteCategory($this->faqAdmin, $target);
    $beforeCategory = $category->getAttributes();
    $beforeItem = $item->getAttributes();

    $operations = [
        fn () => $this->faqService->createCategory($this->faqAdmin, ['code' => 'forged', 'title' => 'Нет']),
        fn () => $this->faqService->createCategory($this->faqAdmin, ['title' => '<b>HTML</b>']),
        fn () => $this->faqService->updateCategory($this->faqAdmin, $category, ['code' => 'changed']),
        fn () => $this->faqService->updateCategory($this->faqAdmin, $category, ['is_active' => 1]),
        fn () => $this->faqService->createItem($this->faqAdmin, $category, ['code' => 'forged', 'question' => 'Нет?', 'answer' => 'Нет']),
        fn () => $this->faqService->createItem($this->faqAdmin, $category, ['question' => '<i>HTML?</i>', 'answer' => 'Нет']),
        fn () => $this->faqService->updateItem($this->faqAdmin, $item, ['answer' => '<script>bad</script>']),
        fn () => $this->faqService->updateItem($this->faqAdmin, $item, ['faq_category_id' => $target->getKey()]),
        fn () => $this->faqService->updateItem($this->faqAdmin, $item, ['faq_category_id' => 999999]),
        fn () => $this->faqService->reorderCategories($this->faqAdmin, array_slice(FaqCategory::query()->pluck('id')->all(), 1)),
    ];

    foreach ($operations as $operation) {
        expect($operation)->toThrow(ValidationException::class);
    }

    expect($category->fresh()->getAttributes())->toEqual($beforeCategory)
        ->and($item->fresh()->getAttributes())->toEqual($beforeItem);
});

test('faq category reorder transaction rolls back earlier updates after an artificial database failure', function (): void {
    $original = FaqCategory::query()->orderBy('id')->pluck('position', 'id')->all();
    $ids = FaqCategory::query()->orderBy('id')->pluck('id')->reverse()->values()->all();
    $failingId = $ids[2];

    DB::unprepared("CREATE TRIGGER fail_faq_category_reorder BEFORE UPDATE OF position ON faq_categories WHEN NEW.id = {$failingId} BEGIN SELECT RAISE(ABORT, 'forced category reorder failure'); END");

    try {
        expect(fn () => $this->faqService->reorderCategories($this->faqAdmin, $ids))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_faq_category_reorder');
    }

    expect(FaqCategory::query()->orderBy('id')->pluck('position', 'id')->all())->toBe($original);
});

test('faq category and item lifecycle preserves invariants and prevents force deletion', function (): void {
    $category = $this->faqService->createCategory($this->faqAdmin, ['title' => 'Временная']);
    $item = $this->faqService->createItem($this->faqAdmin, $category, ['question' => 'Временный вопрос?', 'answer' => 'Ответ']);

    expect(fn () => $this->faqService->deleteCategory($this->faqAdmin, $category))->toThrow(ValidationException::class);
    expect(fn () => $category->delete())->toThrow(ValidationException::class);

    expect($this->faqService->deleteItem($this->faqAdmin, $item))->toBeTrue();
    expect(FaqItem::query()->find($item->getKey()))->toBeNull()
        ->and(FaqItem::withTrashed()->findOrFail($item->getKey())->trashed())->toBeTrue();
    $restored = $this->faqService->restoreItem($this->faqAdmin, $item);
    expect($restored->trashed())->toBeFalse();

    expect($this->faqService->deleteItem($this->faqAdmin, $restored))->toBeTrue();
    expect($this->faqService->deleteCategory($this->faqAdmin, $category))->toBeTrue();
    expect(FaqCategory::withTrashed()->findOrFail($category->getKey())->trashed())->toBeTrue();
    expect(fn () => $this->faqService->restoreItem($this->faqAdmin, $restored))->toThrow(ValidationException::class);
    $restoredCategory = $this->faqService->restoreCategory($this->faqAdmin, $category);
    expect($restoredCategory->trashed())->toBeFalse();
    expect($this->faqService->restoreItem($this->faqAdmin, $restored)->trashed())->toBeFalse();

    expect(fn () => $restoredCategory->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $restoredCategory->replicate())->toThrow(ValidationException::class)
        ->and(fn () => $restored->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $restored->replicate())->toThrow(ValidationException::class);
});

test('faq model guards reject invalid codes direct code changes category moves and deleted parents', function (): void {
    $category = FaqCategory::query()->where('code', 'products')->firstOrFail();
    $other = $this->faqService->createCategory($this->faqAdmin, ['title' => 'Пустая категория']);
    $item = FaqItem::query()->where('code', 'products_availability')->firstOrFail();

    expect(fn () => FaqCategory::query()->create(['code' => 'BAD-CODE', 'title' => 'Нет']))->toThrow(ValidationException::class);
    expect(fn () => FaqItem::query()->create([
        'faq_category_id' => $category->getKey(),
        'code' => 'BAD-CODE',
        'question' => 'Нет?',
        'answer' => 'Нет',
    ]))->toThrow(ValidationException::class);

    $category->code = 'changed';
    expect(fn () => $category->save())->toThrow(ValidationException::class);
    $item->code = 'changed_item';
    expect(fn () => $item->save())->toThrow(ValidationException::class);

    $item->refresh()->faq_category_id = $other->getKey();
    expect(fn () => $item->save())->toThrow(ValidationException::class);
    $this->faqService->deleteCategory($this->faqAdmin, $other);
    expect(fn () => FaqItem::query()->create([
        'faq_category_id' => $other->getKey(),
        'code' => 'faq_item_01arz3ndektsv4rrffq69g5fav',
        'question' => 'Нет?',
        'answer' => 'Нет',
    ]))->toThrow(ValidationException::class);
});

test('manager customer inactive and blocked users cannot bypass faq service authorization', function (User $actor): void {
    $category = FaqCategory::query()->where('code', 'products')->firstOrFail();
    $item = FaqItem::query()->where('code', 'products_availability')->firstOrFail();
    $operations = [
        fn () => $this->faqService->createCategory($actor, ['title' => 'Нет']),
        fn () => $this->faqService->updateCategory($actor, $category, ['title' => 'Нет']),
        fn () => $this->faqService->setCategoryActive($actor, $category, false),
        fn () => $this->faqService->reorderCategories($actor, FaqCategory::query()->pluck('id')->all()),
        fn () => $this->faqService->deleteCategory($actor, $category),
        fn () => $this->faqService->createItem($actor, $category, ['question' => 'Нет?', 'answer' => 'Нет']),
        fn () => $this->faqService->updateItem($actor, $item, ['question' => 'Нет?']),
        fn () => $this->faqService->setItemActive($actor, $item, false),
        fn () => $this->faqService->setItemFeatured($actor, $item, true),
        fn () => $this->faqService->deleteItem($actor, $item),
    ];

    foreach ($operations as $operation) {
        expect($operation)->toThrow(AuthorizationException::class);
    }
})->with([
    'manager' => fn () => User::factory()->manager()->create(),
    'customer' => fn () => User::factory()->create(),
    'inactive admin' => fn () => User::factory()->admin()->inactive()->create(),
    'blocked admin' => fn () => User::factory()->admin()->blocked()->create(),
]);
