<?php

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\CartManager;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Import\ImportProductFactory;
use App\Services\StorefrontProductAvailability;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([ProductCatalogSeeder::class, PartTypeSeeder::class, ProductOptionSeeder::class]);
    app(CatalogConfigurations::class)->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    Queue::fake();
});

function referenceCsvRun(): ImportRun
{
    return ImportRun::factory()->create([
        'type' => 'catalog', 'status' => ImportRunStatus::RunningRows, 'total_rows' => 1,
    ]);
}

function referenceCsvProduct(string $slug, ?VehicleGeneration $generation = null): Product
{
    $type = PartType::where('full_slug', $slug)->firstOrFail();
    $category = $type->productCategory()->firstOrFail();

    return app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: referenceCsvRun(),
        generation: $generation ?? VehicleGeneration::factory()->create(),
        partType: $type,
        storeCategory: $category,
        detailHeader: ['index' => 6, 'group' => null, 'title' => $type->title, 'category_title' => $category->title],
        cellValue: '1',
    );
}

test('catalog product reference price and sellable variants follow the installed CSV', function (string $slug): void {
    $product = referenceCsvProduct($slug);
    $matrix = app(CatalogConfigurations::class);
    $rows = $matrix->rows($product->part_type_id);
    $variants = $product->variants()->with('optionValues.group')->get();

    expect($rows)->not->toBeEmpty()
        ->and($variants)->toHaveCount($rows->count())
        ->and($variants->pluck('catalog_configuration_id')->filter()->count())->toBe($rows->count())
        ->and($product->price)->toBe($product->defaultVariant()->firstOrFail()->price)
        ->and($product->old_price)->toBeNull();

    foreach ($rows as $row) {
        $variant = $variants->first(fn (ProductVariant $variant): bool => $matrix->signature($variant) === $row->signature);
        expect($variant)->not->toBeNull()
            ->and($variant->price)->toBe($row->price)
            ->and($variant->old_price)->toBeNull();
    }
})->with([
    'porog' => ['porog'],
    'rear_arch' => ['arka/zadniaia'],
    'front_arch' => ['arka/peredniaia'],
    'rear_inner_arch' => ['arka/vnutrenniaia-zadniaia'],
    'front_inner_arch' => ['arka/vnutrenniaia-peredniaia'],
    'universal_inner_arch' => ['arka/vnutrenniaia-universalnaia'],
    'rear_door_panel' => ['penka/zadnei-dveri'],
    'trunk_panel' => ['penka/bagazhnika'],
    'longeron' => ['lonzheron'],
    'floor_patch' => ['remkomplekt-pola'],
]);

test('missing CSV prices fail explicitly instead of inheriting a guessed type price', function (): void {
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    DB::table('catalog_configurations')->where('part_type_id', $type->id)->update(['is_active' => false]);
    app(CatalogConfigurations::class)->clear();
    expect(fn () => referenceCsvProduct('porog'))->toThrow(ValidationException::class, 'Не установлены цены CSV');
    expect(Product::count())->toBe(0);
});

test('repeated import restores a legacy manual price but does not add more variants or history when unchanged', function (): void {
    $generation = VehicleGeneration::factory()->create();
    $product = referenceCsvProduct('porog', $generation);
    $variant = $product->variants()->firstOrFail();
    $csvPrice = $variant->configuration()->firstOrFail()->price;
    $originalIds = $product->variants()->orderBy('id')->pluck('id')->all();
    DB::table('product_variants')->where('id', $variant->id)->update([
        'price' => '7777.77', 'price_mode' => 'individual',
    ]);
    referenceCsvProduct('porog', $generation);
    expect($variant->fresh()->price)->toBe($csvPrice)
        ->and($variant->price_mode)->toBe('inherited')
        ->and($product->variants()->orderBy('id')->pluck('id')->all())->toBe($originalIds)
        ->and(DB::table('catalog_matrix_price_history')->where('product_variant_id', $variant->id)->where('old_price', '7777.77')->count())->toBe(1);

    referenceCsvProduct('porog', $generation);
    expect(DB::table('catalog_matrix_price_history')->where('product_variant_id', $variant->id)->where('old_price', '7777.77')->count())->toBe(1)
        ->and($product->variants()->orderBy('id')->pluck('id')->all())->toBe($originalIds);
});

test('CSV configurations are sellable and the cart freezes their actual selected prices', function (): void {
    $product = referenceCsvProduct('porog');
    DB::table('products')->where('id', $product->id)->update([
        'status' => ProductStatus::Active->value, 'import_present' => true,
    ]);
    $default = $product->fresh()->defaultVariant()->firstOrFail();
    $cart = Cart::factory()->create();
    $request = Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
    $item = app(CartManager::class)->addItem($request, $default->id);
    expect(app(StorefrontProductAvailability::class)->hasSellablePrice($default))->toBeTrue()
        ->and($item->price_snapshot)->toBe($default->price)
        ->and($default->price)->toBe($default->configuration()->firstOrFail()->price);
});

test('existing non-CSV part types cannot create imported variants from legacy base prices', function (): void {
    $type = PartType::where('full_slug', 'arka/karman-zadniaia')->firstOrFail();
    expect(app(CatalogConfigurations::class)->rows($type->id))->toBeEmpty();
    expect(fn () => referenceCsvProduct($type->full_slug))->toThrow(ValidationException::class);
    expect(Product::count())->toBe(0);
});
