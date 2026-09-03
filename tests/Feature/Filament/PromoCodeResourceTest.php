<?php

use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Resources\PromoCodes\Pages\ViewPromoCode;
use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('promo resource provides required sections statistics filters and bounded selector', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $promo = PromoCode::factory()->create(['usage_limit' => 10]);
    PromoCodeRedemption::factory()->count(3)->for($promo)->create();

    Livewire::test(ListPromoCodes::class)
        ->assertCanSeeTableRecords([$promo])
        ->assertTableColumnExists('active_redemptions_count')
        ->assertTableColumnExists('discount_total_sum')
        ->assertTableFilterExists('current_status');

    Livewire::test(ViewPromoCode::class, ['record' => $promo->getKey()])
        ->assertSee('Текущий статус')
        ->assertSee('Использовано')
        ->assertSee('Лимит')
        ->assertSee('Доступно')
        ->assertSee('Сумма выданных скидок');

    Product::factory()->count(55)->create(['title' => 'Bounded promo selector']);
    $method = new ReflectionMethod(PromoCodeResource::class, 'productSearch');
    $results = $method->invoke(null, 'Bounded promo selector');

    expect($results)->toHaveCount(50);

    $source = file_get_contents(app_path('Filament/Resources/PromoCodes/PromoCodeResource.php'));
    foreach (['Основное', 'Скидка и ограничения', 'Область действия', 'Статистика'] as $section) {
        expect($source)->toContain("Section::make('{$section}')");
    }
    expect($source)->toContain('generateUniqueCode', 'без потомков', '->limit(50)')
        ->not->toContain('Product::all()');
});

test('promo list aggregates redemptions without per record queries', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $promos = PromoCode::factory()->count(8)->create();
    foreach ($promos as $promo) {
        PromoCodeRedemption::factory()->for($promo)->create();
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    Livewire::test(ListPromoCodes::class)->assertCanSeeTableRecords($promos);

    $standaloneRedemptionQueries = collect($queries)->filter(fn (string $sql): bool => preg_match('/^select .* from ["`]?promo_code_redemptions/i', $sql) === 1);
    expect($standaloneRedemptionQueries->count())->toBeLessThanOrEqual(1);
});

test('current status filter returns only the requested computed state', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $active = PromoCode::factory()->create(['usage_limit' => 1]);
    PromoCodeRedemption::factory()->for($active)->released()->create();
    $scheduled = PromoCode::factory()->create(['starts_at' => now()->addDay()]);
    $expired = PromoCode::factory()->create(['ends_at' => now()->subDay()]);
    $exhausted = PromoCode::factory()->create(['usage_limit' => 1]);
    PromoCodeRedemption::factory()->for($exhausted)->create();
    $disabled = PromoCode::factory()->create(['is_active' => false]);
    $records = collect([$active, $scheduled, $expired, $exhausted, $disabled]);

    foreach ([
        'active' => $active,
        'scheduled' => $scheduled,
        'expired' => $expired,
        'exhausted' => $exhausted,
        'disabled' => $disabled,
    ] as $status => $expected) {
        Livewire::test(ListPromoCodes::class)
            ->filterTable('current_status', $status)
            ->assertCanSeeTableRecords([$expected])
            ->assertCanNotSeeTableRecords($records->reject(fn (PromoCode $promo): bool => $promo->is($expected)));
    }
});

test('manager has index and view only while customer and disabled users are denied', function (string $role, bool $index, bool $view): void {
    $promo = PromoCode::factory()->create();
    $actor = match ($role) {
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive' => User::factory()->admin()->inactive()->create(),
        default => User::factory()->admin()->blocked()->create(),
    };
    $this->actingAs($actor);

    $indexResponse = $this->get(PromoCodeResource::getUrl('index'));
    $viewResponse = $this->get(PromoCodeResource::getUrl('view', ['record' => $promo]));
    $createResponse = $this->get(PromoCodeResource::getUrl('create'));
    $editResponse = $this->get(PromoCodeResource::getUrl('edit', ['record' => $promo]));

    expect($indexResponse->isOk())->toBe($index)
        ->and($viewResponse->isOk())->toBe($view)
        ->and($createResponse->isOk())->toBeFalse()
        ->and($editResponse->isOk())->toBeFalse()
        ->and($createResponse->status())->not->toBe(500)
        ->and($editResponse->status())->not->toBe(500);
})->with([
    'manager' => ['manager', true, true],
    'customer' => ['customer', false, false],
    'inactive admin' => ['inactive', false, false],
    'blocked admin' => ['blocked', false, false],
]);
