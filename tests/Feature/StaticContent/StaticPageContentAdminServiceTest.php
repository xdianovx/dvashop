<?php

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use App\Models\User;
use App\Services\StaticContent\StaticPageContentAdminService;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(StaticPageContentSeeder::class);
    $this->staticService = app(StaticPageContentAdminService::class);
    $this->staticAdmin = User::factory()->admin()->create();
});

test('admin and super admin update normalize toggle and reorder fixed static content', function (string $state): void {
    $actor = User::factory()->{$state}()->create();
    $page = StaticPage::query()->where('code', StaticPageCode::About)->firstOrFail();
    $section = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutHero)->firstOrFail();
    $item = StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricParts)->firstOrFail();

    $updatedPage = $this->staticService->updatePage($actor, $page, [
        'title' => '  О компании  ',
        'subtitle' => '   ',
        'primary_action_label' => '  Позвонить  ',
        'position' => 12,
    ]);
    $updatedSection = $this->staticService->updateSection($actor, $section, [
        'label' => '  Компания  ',
        'body' => '  Новый обычный текст  ',
        'position' => 13,
    ]);
    $updatedItem = $this->staticService->updateItem($actor, $item, [
        'label' => '   ',
        'title' => '  200 000+ деталей  ',
        'text' => '  Обновлённый показатель  ',
        'position' => 14,
    ]);

    expect($updatedPage->title)->toBe('О компании')
        ->and($updatedPage->subtitle)->toBeNull()
        ->and($updatedPage->primary_action_label)->toBe('Позвонить')
        ->and($updatedPage->position)->toBe(12)
        ->and($updatedSection->label)->toBe('Компания')
        ->and($updatedSection->body)->toBe('Новый обычный текст')
        ->and($updatedItem->label)->toBeNull()
        ->and($updatedItem->title)->toBe('200 000+ деталей')
        ->and($updatedItem->text)->toBe('Обновлённый показатель');

    expect($this->staticService->setPageActive($actor, $page, false)->is_active)->toBeFalse()
        ->and($this->staticService->setSectionActive($actor, $section, false)->is_active)->toBeFalse()
        ->and($this->staticService->setItemActive($actor, $item, false)->is_active)->toBeFalse();

    $ids = StaticPage::query()->ordered()->pluck('id')->reverse()->values()->all();
    $this->staticService->reorderPages($actor, $ids);
    expect(StaticPage::query()->ordered()->pluck('id')->all())->toBe($ids)
        ->and(StaticPage::query()->ordered()->pluck('position')->all())->toBe(range(0, count($ids) - 1));
})->with(['admin', 'superAdmin']);

test('static service rejects unexpected html system fields invalid booleans empty items and incomplete reorder atomically', function (): void {
    $page = StaticPage::query()->where('code', StaticPageCode::About)->firstOrFail();
    $section = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutHero)->firstOrFail();
    $item = StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricParts)->firstOrFail();
    $beforePage = $page->getAttributes();
    $beforeSection = $section->getAttributes();
    $beforeItem = $item->getAttributes();

    $operations = [
        fn () => $this->staticService->updatePage($this->staticAdmin, $page, ['slug' => 'about']),
        fn () => $this->staticService->updatePage($this->staticAdmin, $page, ['title' => '<b>О нас</b>']),
        fn () => $this->staticService->updatePage($this->staticAdmin, $page, ['code' => StaticPageCode::How->value]),
        fn () => $this->staticService->updatePage($this->staticAdmin, $page, ['is_active' => 1]),
        fn () => $this->staticService->updateSection($this->staticAdmin, $section, ['static_page_id' => StaticPage::query()->where('code', StaticPageCode::How)->value('id')]),
        fn () => $this->staticService->updateSection($this->staticAdmin, $section, ['body' => '<script>alert(1)</script>']),
        fn () => $this->staticService->updateItem($this->staticAdmin, $item, ['static_page_section_id' => StaticPageSection::query()->where('code', StaticPageSectionCode::AboutTechnologies)->value('id')]),
        fn () => $this->staticService->updateItem($this->staticAdmin, $item, ['label' => ' ', 'title' => ' ', 'text' => ' ']),
        fn () => $this->staticService->reorderPages($this->staticAdmin, StaticPage::query()->ordered()->pluck('id')->slice(1)->values()->all()),
        fn () => $this->staticService->reorderPages($this->staticAdmin, [...StaticPage::query()->pluck('id')->all(), 999999]),
        fn () => $this->staticService->reorderPages($this->staticAdmin, array_map('strval', StaticPage::query()->pluck('id')->all())),
    ];

    foreach ($operations as $operation) {
        expect($operation)->toThrow(ValidationException::class);
    }

    expect($page->fresh()->getAttributes())->toEqual($beforePage)
        ->and($section->fresh()->getAttributes())->toEqual($beforeSection)
        ->and($item->fresh()->getAttributes())->toEqual($beforeItem);
});

test('page reorder transaction rolls back earlier updates after an artificial database failure', function (): void {
    $original = StaticPage::query()->orderBy('id')->pluck('position', 'id')->all();
    $ids = StaticPage::query()->orderBy('id')->pluck('id')->reverse()->values()->all();
    $failingId = $ids[2];

    DB::unprepared("CREATE TRIGGER fail_static_page_reorder BEFORE UPDATE OF position ON static_pages WHEN NEW.id = {$failingId} BEGIN SELECT RAISE(ABORT, 'forced page reorder failure'); END");

    try {
        expect(fn () => $this->staticService->reorderPages($this->staticAdmin, $ids))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_static_page_reorder');
    }

    expect(StaticPage::query()->orderBy('id')->pluck('position', 'id')->all())->toBe($original);
});

test('fixed static model guards reject unknown codes parent moves deletion force deletion and replication', function (): void {
    $page = StaticPage::query()->where('code', StaticPageCode::About)->firstOrFail();
    $section = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutHero)->firstOrFail();
    $item = StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricParts)->firstOrFail();

    expect(fn () => $page->code = 'unknown')->toThrow(ValidationException::class);
    $page->code = StaticPageCode::How;
    expect(fn () => $page->save())->toThrow(ValidationException::class)
        ->and(fn () => $page->delete())->toThrow(ValidationException::class)
        ->and(fn () => $page->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $page->replicate())->toThrow(ValidationException::class);

    $section->static_page_id = StaticPage::query()->where('code', StaticPageCode::How)->value('id');
    expect(fn () => $section->save())->toThrow(ValidationException::class)
        ->and(fn () => $section->delete())->toThrow(ValidationException::class)
        ->and(fn () => $section->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $section->replicate())->toThrow(ValidationException::class);

    $item->static_page_section_id = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutTechnologies)->value('id');
    expect(fn () => $item->save())->toThrow(ValidationException::class)
        ->and(fn () => $item->delete())->toThrow(ValidationException::class)
        ->and(fn () => $item->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $item->replicate())->toThrow(ValidationException::class);

    expect(fn () => StaticPageSection::query()->create([
        'static_page_id' => StaticPage::query()->where('code', StaticPageCode::How)->value('id'),
        'code' => StaticPageSectionCode::AboutGoal,
    ]))->toThrow(ValidationException::class);
    expect(fn () => StaticPageItem::query()->create([
        'static_page_section_id' => StaticPageSection::query()->where('code', StaticPageSectionCode::AboutMetrics)->value('id'),
        'code' => StaticPageItemCode::HowStepChoose,
        'title' => 'Ошибка',
    ]))->toThrow(ValidationException::class);
});

test('manager customer inactive and blocked users cannot bypass static service authorization', function (User $actor): void {
    $page = StaticPage::query()->firstOrFail();
    $section = StaticPageSection::query()->firstOrFail();
    $item = StaticPageItem::query()->firstOrFail();
    $operations = [
        fn () => $this->staticService->updatePage($actor, $page, ['title' => 'Нет']),
        fn () => $this->staticService->setPageActive($actor, $page, false),
        fn () => $this->staticService->reorderPages($actor, StaticPage::query()->pluck('id')->all()),
        fn () => $this->staticService->updateSection($actor, $section, ['title' => 'Нет']),
        fn () => $this->staticService->setSectionActive($actor, $section, false),
        fn () => $this->staticService->updateItem($actor, $item, ['title' => 'Нет']),
        fn () => $this->staticService->setItemActive($actor, $item, false),
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
