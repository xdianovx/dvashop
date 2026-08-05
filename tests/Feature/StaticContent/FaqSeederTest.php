<?php

use App\Models\FaqCategory;
use App\Models\FaqItem;
use Database\Seeders\FaqSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('faq seeder creates all categories questions and featured inventory without html', function (): void {
    $this->seed(FaqSeeder::class);

    expect(FaqCategory::query()->count())->toBe(6)
        ->and(FaqItem::query()->count())->toBe(18)
        ->and(FaqItem::query()->where('is_featured', true)->count())->toBe(5)
        ->and(FaqCategory::query()->pluck('code')->sort()->values()->all())->toBe(collect([
            'common', 'products', 'payment_delivery', 'exchange_returns', 'website', 'partners',
        ])->sort()->values()->all())
        ->and(FaqItem::query()->pluck('code')->sort()->values()->all())->toBe(collect([
            'common_quality_exchange', 'common_exchange_time', 'common_return_process', 'common_refund_process',
            'common_refund_destination', 'common_defect_return', 'products_availability', 'products_material',
            'products_preparation', 'payment_methods', 'delivery_process', 'delivery_cost', 'returns_period',
            'returns_shipping_cost', 'website_find_part', 'website_registration', 'partners_wholesale', 'partners_discounts',
        ])->sort()->values()->all());

    $content = FaqCategory::query()->pluck('title')
        ->merge(FaqItem::query()->pluck('question'))
        ->merge(FaqItem::query()->pluck('answer'))
        ->implode("\n");
    expect($content)->not->toMatch('/<[^>]+>/');

    $featuredCodes = FaqItem::query()->where('is_featured', true)->ordered()->pluck('code')->all();
    expect($featuredCodes)->toBe([
        'common_quality_exchange',
        'common_exchange_time',
        'common_return_process',
        'common_refund_process',
        'common_refund_destination',
    ]);
});

test('faq seeder is idempotent and preserves administrator changes', function (): void {
    $this->seed(FaqSeeder::class);
    $category = FaqCategory::query()->where('code', 'common')->firstOrFail();
    $item = FaqItem::query()->where('code', 'common_quality_exchange')->firstOrFail();

    $category->forceFill(['title' => 'Ручная категория', 'position' => 701, 'is_active' => false])->save();
    $item->forceFill([
        'question' => 'Ручной вопрос?',
        'answer' => 'Ручной ответ',
        'position' => 702,
        'is_active' => false,
        'is_featured' => false,
    ])->save();

    $this->seed(FaqSeeder::class);

    expect(FaqCategory::query()->count())->toBe(6)
        ->and(FaqItem::query()->count())->toBe(18)
        ->and($category->refresh()->title)->toBe('Ручная категория')
        ->and($category->position)->toBe(701)
        ->and($category->is_active)->toBeFalse()
        ->and($item->refresh()->question)->toBe('Ручной вопрос?')
        ->and($item->answer)->toBe('Ручной ответ')
        ->and($item->position)->toBe(702)
        ->and($item->is_active)->toBeFalse()
        ->and($item->is_featured)->toBeFalse();
});

test('faq seeder rolls back categories and items after an artificial failure', function (): void {
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_faq_item_seed
        BEFORE INSERT ON faq_items
        WHEN NEW.code = 'delivery_process'
        BEGIN
            SELECT RAISE(ABORT, 'forced faq seeder failure');
        END
    SQL);

    try {
        expect(fn () => $this->seed(FaqSeeder::class))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_faq_item_seed');
    }

    expect(FaqCategory::query()->count())->toBe(0)
        ->and(FaqItem::query()->count())->toBe(0);
});
