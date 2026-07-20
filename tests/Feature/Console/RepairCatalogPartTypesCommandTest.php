<?php

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function command_legacy_category(string $title, string $slug, ?ProductCategory $parent = null): ProductCategory
{
    $factory = ProductCategory::factory();

    if ($parent instanceof ProductCategory) {
        $factory = $factory->forParent($parent);
    }

    return $factory->create(['title' => $title, 'slug' => $slug]);
}

test('repair command defaults to dry run and changes no rows', function () {
    $legacy = command_legacy_category('Порог', 'legacy-porog');
    $product = Product::factory()->forCategory($legacy)->generic()->create()->fresh();
    $before = [
        'categories' => ProductCategory::withTrashed()->get()->map->getAttributes()->all(),
        'part_types' => PartType::withTrashed()->count(),
        'product' => $product->getAttributes(),
    ];

    $this->artisan('catalog:repair-part-types')
        ->expectsOutputToContain('Диагностика переноса типов деталей')
        ->expectsOutputToContain('Режим: DRY-RUN')
        ->expectsOutputToContain('Изменения в базе не выполняются.')
        ->expectsOutputToContain('До следующего этапа интеграции импорта новый Excel-импорт запускать нельзя:')
        ->assertExitCode(0);

    expect(ProductCategory::withTrashed()->get()->map->getAttributes()->all())->toBe($before['categories'])
        ->and(PartType::withTrashed()->count())->toBe($before['part_types'])
        ->and($product->fresh()->getAttributes())->toBe($before['product']);
});

test('repair command dry run is safe on a clean seeded catalog', function () {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(PartTypeSeeder::class);
    $before = [
        ProductCategory::withTrashed()->count(),
        PartType::withTrashed()->count(),
        Product::withTrashed()->count(),
    ];

    $this->artisan('catalog:repair-part-types')
        ->expectsOutputToContain('Технические категории распознаны')
        ->expectsOutputToContain('Dry-run завершён. База данных не изменялась.')
        ->assertExitCode(0);

    expect([
        ProductCategory::withTrashed()->count(),
        PartType::withTrashed()->count(),
        Product::withTrashed()->count(),
    ])->toBe($before);
});

test('repair command apply updates products and prints actual counters', function () {
    $legacy = command_legacy_category('Порог', 'legacy-porog');
    $product = Product::factory()->forCategory($legacy)->generic()->create();

    $this->artisan('catalog:repair-part-types --apply')
        ->expectsOutputToContain('Режим: APPLY')
        ->expectsOutputToContain('Применение repair')
        ->expectsOutputToContain('Repair успешно применён.')
        ->expectsOutputToContain('Импорт в этом этапе не изменён.')
        ->assertExitCode(0);

    expect($product->fresh()->part_type_id)->not->toBeNull()
        ->and($product->fresh()->product_category_id)->not->toBe($legacy->id)
        ->and($legacy->fresh()->is_active)->toBeFalse();
});

test('repair command reports technical categories kept active for manual review', function () {
    $root = command_legacy_category('Порог', 'legacy-porog');
    $suspect = command_legacy_category('Декоративная накладка', 'legacy-decorative-trim', $root);
    $product = Product::factory()->forCategory($suspect)->generic()->create()->fresh();

    $this->artisan('catalog:repair-part-types --apply')
        ->expectsOutputToContain('Технические категории оставлены активными')
        ->expectsOutputToContain('Категория «Порог» оставлена активной')
        ->assertExitCode(0);

    expect($root->fresh()->is_active)->toBeTrue()
        ->and($suspect->fresh()->is_active)->toBeTrue()
        ->and($product->fresh()->product_category_id)->toBe($suspect->id)
        ->and($product->fresh()->part_type_id)->toBeNull();
});

test('repair command returns one for blockers and rolls back every planned change', function () {
    $root = command_legacy_category('Кузовные детали', 'kuzovnye-detali');
    $legacy = command_legacy_category('Пороги', 'porogi', $root);
    command_legacy_category('Экспериментальная подкатегория', 'experiment', $legacy);
    $product = Product::factory()->forCategory($legacy)->create()->fresh();
    $categoryCount = ProductCategory::withTrashed()->count();

    $this->artisan('catalog:repair-part-types --apply')
        ->expectsOutputToContain('[BLOCKER]')
        ->expectsOutputToContain('Repair остановлен')
        ->assertExitCode(1);

    expect(ProductCategory::withTrashed()->count())->toBe($categoryCount)
        ->and(PartType::withTrashed()->count())->toBe(0)
        ->and($product->fresh()->product_category_id)->toBe($legacy->id)
        ->and($legacy->fresh()->is_active)->toBeTrue();
});
