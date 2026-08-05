<?php

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('static page content seeder creates complete relational inventory without forbidden content', function (): void {
    $this->seed(StaticPageContentSeeder::class);

    expect(StaticPage::query()->count())->toBe(5)
        ->and(StaticPageSection::query()->count())->toBe(8)
        ->and(StaticPageItem::query()->count())->toBe(24)
        ->and(StaticPage::query()->pluck('code')->map(fn ($code): string => $code->value)->sort()->values()->all())
        ->toBe(collect(StaticPageCode::cases())->map->value->sort()->values()->all())
        ->and(StaticPageSection::query()->pluck('code')->map(fn ($code): string => $code->value)->sort()->values()->all())
        ->toBe(collect(StaticPageSectionCode::cases())->map->value->sort()->values()->all())
        ->and(StaticPageItem::query()->pluck('code')->map(fn ($code): string => $code->value)->sort()->values()->all())
        ->toBe(collect(StaticPageItemCode::cases())->map->value->sort()->values()->all());

    foreach (StaticPageSection::query()->with('page')->get() as $section) {
        expect($section->code->page())->toBe($section->page->code);
    }
    foreach (StaticPageItem::query()->with('section')->get() as $item) {
        expect($item->code->section())->toBe($item->section->code);
    }

    $content = collect([
        ...StaticPage::query()->get()->flatMap->only(['title', 'subtitle', 'primary_action_label', 'secondary_action_label'])->filter()->all(),
        ...StaticPageSection::query()->get()->flatMap->only(['label', 'title', 'subtitle', 'body'])->filter()->all(),
        ...StaticPageItem::query()->get()->flatMap->only(['label', 'title', 'text'])->filter()->all(),
    ])->implode("\n");

    expect($content)->not->toMatch('/<[^>]+>/')
        ->not->toContain('88001005625')
        ->not->toContain('79395554925')
        ->not->toContain('79062444151')
        ->not->toContain('http://')
        ->not->toContain('https://')
        ->not->toContain('tel:')
        ->not->toContain('mailto:');

    expect(StaticPageSection::query()->whereHas('page', fn ($query) => $query->where('code', StaticPageCode::Payment->value))->count())->toBe(0);
});

test('static page content seeder is idempotent and preserves administrator edits', function (): void {
    $this->seed(StaticPageContentSeeder::class);
    $page = StaticPage::query()->where('code', StaticPageCode::About)->firstOrFail();
    $section = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutHero)->firstOrFail();
    $item = StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricParts)->firstOrFail();

    $page->forceFill(['title' => 'Ручной заголовок', 'position' => 777, 'is_active' => false])->save();
    $section->forceFill(['body' => 'Ручной текст блока', 'position' => 778, 'is_active' => false])->save();
    $item->forceFill(['text' => 'Ручной текст элемента', 'position' => 779, 'is_active' => false])->save();

    $this->seed(StaticPageContentSeeder::class);

    expect(StaticPage::query()->count())->toBe(5)
        ->and(StaticPageSection::query()->count())->toBe(8)
        ->and(StaticPageItem::query()->count())->toBe(24)
        ->and($page->refresh()->title)->toBe('Ручной заголовок')
        ->and($page->position)->toBe(777)
        ->and($page->is_active)->toBeFalse()
        ->and($section->refresh()->body)->toBe('Ручной текст блока')
        ->and($section->position)->toBe(778)
        ->and($section->is_active)->toBeFalse()
        ->and($item->refresh()->text)->toBe('Ручной текст элемента')
        ->and($item->position)->toBe(779)
        ->and($item->is_active)->toBeFalse();
});

test('static page content seeder rolls back every inserted record after an artificial failure', function (): void {
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_static_page_item_seed
        BEFORE INSERT ON static_page_items
        WHEN NEW.code = 'how_step_confirm'
        BEGIN
            SELECT RAISE(ABORT, 'forced static content seeder failure');
        END
    SQL);

    try {
        expect(fn () => $this->seed(StaticPageContentSeeder::class))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_static_page_item_seed');
    }

    expect(StaticPage::query()->count())->toBe(0)
        ->and(StaticPageSection::query()->count())->toBe(0)
        ->and(StaticPageItem::query()->count())->toBe(0);
});
