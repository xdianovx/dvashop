<?php

use App\Mail\CustomerOrderCreatedMail;
use App\Mail\ManagerOrderCreatedMail;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('order display title removes only the complete saved trailing option list', function (?string $title, ?array $options, string $expected): void {
    $item = new OrderItem(['title_snapshot' => $title, 'options_snapshot' => $options]);
    $before = $item->getAttributes();
    $item->setRelation('product', new Product(['title' => 'Current catalog title must not be used']));

    expect($item->storefrontTitle())->toBe($expected)
        ->and($item->getAttributes())->toBe($before);
})->with([
    'exact suffix' => ['Товар ABC — Профиль: Полный; Материал: Оцинковка', ['Профиль' => 'Полный', 'Материал' => 'Оцинковка'], 'Товар ABC'],
    'no duplicate' => ['Товар ABC', ['Профиль' => 'Полный', 'Материал' => 'Оцинковка'], 'Товар ABC'],
    'partial suffix' => ['Товар ABC — Профиль: Полный', ['Профиль' => 'Полный', 'Материал' => 'Оцинковка'], 'Товар ABC — Профиль: Полный'],
    'middle text' => ['Товар — Профиль: Полный; Материал: Оцинковка — усиленный', ['Профиль' => 'Полный', 'Материал' => 'Оцинковка'], 'Товар — Профиль: Полный; Материал: Оцинковка — усиленный'],
    'similar text' => ['Товар ABC — Материал: Сталь', ['Материал' => 'Оцинковка'], 'Товар ABC — Материал: Сталь'],
    'missing separator' => ['Товар ABC Профиль: Полный', ['Профиль' => 'Полный'], 'Товар ABC Профиль: Полный'],
    'empty options' => ['Товар ABC — Профиль: Полный', [], 'Товар ABC — Профиль: Полный'],
    'null options' => ['Товар ABC', null, 'Товар ABC'],
    'null old snapshot' => [null, null, ''],
    'empty old snapshot' => ['', [], ''],
    'empty title with options' => ['', ['Материал' => 'Сталь'], ''],
    'never blank the title' => [' — Материал: Сталь', ['Материал' => 'Сталь'], ' — Материал: Сталь'],
    'reordered complete list' => ['Товар ABC — Материал: Оцинковка; Профиль: Полный', ['Профиль' => 'Полный', 'Материал' => 'Оцинковка'], 'Товар ABC'],
    'extra option' => ['Товар ABC — Материал: Оцинковка; Профиль: Полный; Сторона: Левая', ['Профиль' => 'Полный', 'Материал' => 'Оцинковка'], 'Товар ABC — Материал: Оцинковка; Профиль: Полный; Сторона: Левая'],
    'repeated option is not a complete match' => ['Товар ABC — Профиль: Полный; Профиль: Полный', ['Профиль' => 'Полный', 'Материал' => 'Оцинковка'], 'Товар ABC — Профиль: Полный; Профиль: Полный'],
    'structured snapshot' => ['Товар ABC — Профиль: Полный', ['profile' => ['group' => 'Профиль', 'value' => 'Полный']], 'Товар ABC'],
]);

test('order display title reads only snapshots without queries or database mutations', function (): void {
    $item = OrderItem::factory()->create([
        'title_snapshot' => 'Исторический товар — Материал: Сталь',
        'options_snapshot' => ['Материал' => 'Сталь'],
    ]);
    $item->product->update(['title' => 'Новый товар']);
    $item = $item->fresh();
    $before = $item->getAttributes();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        expect($item->storefrontTitle())->toBe('Исторический товар')
            ->and(DB::getQueryLog())->toBe([])
            ->and($item->getAttributes())->toBe($before);
    } finally {
        DB::disableQueryLog();
    }
    expect($item->refresh()->getAttributes())->toBe($before);
});

test('order thanks and emails display saved title and options once with escaping', function (bool $reordered, bool $htmlText): void {
    $title = $htmlText ? 'Товар <script>alert(1)</script>' : 'Арка для Honda airwave Универсал 5 дв.';
    $material = $htmlText ? '<b>Оцинковка</b>' : 'Оцинковка';
    $options = ['Профиль' => 'Полный', 'Материал' => $material, 'Положение' => 'Левый'];
    $summary = 'Профиль: Полный; Материал: '.$material.'; Положение: Левый';
    $suffix = $reordered ? 'Профиль: Полный; Положение: Левый; Материал: '.$material : $summary;
    $item = OrderItem::factory()->create(['title_snapshot' => $title.' — '.$suffix, 'options_snapshot' => $options])->fresh();
    $before = $item->getAttributes();
    $item->product->update(['title' => 'Изменённый каталог']);
    $order = $item->order->load('items');
    $token = 'presentation-test-token';
    $response = $this->withSession(['checkout_success.'.$order->id => $token])
        ->get(route('checkout.success', ['order' => $order->number, 'token' => $token]))
        ->assertOk();

    $outputs = [
        $response->getContent(),
        (new CustomerOrderCreatedMail($order, 'Тестовый магазин'))->render(),
        (new ManagerOrderCreatedMail($order, 'Тестовый магазин'))->render(),
    ];
    foreach ($outputs as $html) {
        expect(substr_count($html, e($title)))->toBe(1)
            ->and(substr_count($html, e($summary)))->toBe(1)
            ->and($html)->not->toContain(e($before['title_snapshot']), 'Изменённый каталог', '<script>alert(1)</script>', '<b>Оцинковка</b>');
    }
    expect($item->refresh()->getAttributes())->toBe($before);
})->with([
    'exact' => [false, false],
    'reordered' => [true, false],
    'escaped exact' => [false, true],
    'escaped reordered' => [true, true],
]);

test('old order without snapshot text renders thanks and both emails safely', function (): void {
    $item = OrderItem::factory()->create(['title_snapshot' => '', 'options_snapshot' => null]);
    $order = $item->order->load('items');
    // Some historical in-memory imports contain null even where the DB column is non-nullable.
    $order->items->sole()->title_snapshot = null;

    expect(view('thanks', ['order' => $order])->render())->toContain('Спасибо за заказ');
    expect((new CustomerOrderCreatedMail($order, 'Тестовый магазин'))->render())->toBeString();
    expect((new ManagerOrderCreatedMail($order, 'Тестовый магазин'))->render())->toBeString();
    expect($item->refresh()->title_snapshot)->toBe('')
        ->and($item->options_snapshot)->toBeNull();
});
