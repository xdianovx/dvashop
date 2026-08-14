<?php

use App\Enums\ImportRunStatus;
use App\Models\Cart;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\CartManager;
use App\Services\Import\ImportProductFactory;
use App\Services\StorefrontProductAvailability;
use App\ViewModels\ProductCardViewModel;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(PartTypeSeeder::class);
    $this->seed(ProductOptionSeeder::class);
});

function referencePriceImportRun(): ImportRun
{
    return ImportRun::factory()->create([
        'type' => 'catalog',
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $productState
 * @param  array<string, mixed>|null  $variantState
 */
function referencePriceBackfillProduct(
    string $partTypeFullSlug,
    array $productState = [],
    ?array $variantState = [],
): Product {
    $partType = PartType::query()->where('full_slug', $partTypeFullSlug)->firstOrFail();
    $category = $partType->productCategory()->firstOrFail();
    $token = str_replace('/', '-', $partTypeFullSlug).'-'.strtolower((string) Str::ulid());

    $product = Product::factory()
        ->forCategory($category)
        ->forPartType($partType)
        ->create(array_merge([
            'price' => null,
            'old_price' => null,
            'import_source' => 'catalog',
            'import_key' => 'catalog:test:'.$token,
        ], $productState));

    if ($variantState !== null) {
        ProductVariant::factory()
            ->forProduct($product)
            ->default()
            ->create(array_merge([
                'price' => 0,
                'old_price' => null,
            ], $variantState));
    }

    return $product->refresh();
}

function referencePriceCartRequest(Cart $cart): Request
{
    return Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
}

test('new catalog imports price Product and default Variant from all mapped PartTypes', function (string $fullSlug, string $price, ?string $oldPrice): void {
    Queue::fake();
    $partType = PartType::query()->where('full_slug', $fullSlug)->firstOrFail();
    $category = $partType->productCategory()->firstOrFail();

    $product = app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: referencePriceImportRun(),
        generation: VehicleGeneration::factory()->create(),
        partType: $partType,
        storeCategory: $category,
        detailHeader: ['index' => 6, 'group' => null, 'title' => $partType->title, 'category_title' => $category->title],
        cellValue: '',
    );

    $variant = $product->defaultVariant()->firstOrFail();

    expect($product->price)->toEqual($price)
        ->and($product->old_price)->toEqual($oldPrice)
        ->and($variant->price)->toEqual($price)
        ->and($variant->old_price)->toEqual($oldPrice);
})->with([
    'porog' => ['porog', '1790.00', null],
    'arka zadniaia' => ['arka/zadniaia', '1950.00', null],
    'arka peredniaia' => ['arka/peredniaia', '1950.00', null],
    'arka vnutrenniaia' => ['arka/vnutrenniaia', '2090.00', null],
    'arka vnutrenniaia universalnaia' => ['arka/vnutrenniaia-universalnaia', '2090.00', null],
    'arka karman zadniaia' => ['arka/karman-zadniaia', '1950.00', null],
    'penka zadnei dveri' => ['penka/zadnei-dveri', '2090.00', null],
    'penka perednei dveri' => ['penka/perednei-dveri', '2090.00', null],
    'penka bagazhnika' => ['penka/bagazhnika', '2090.00', '2500.00'],
    'lonzheron' => ['lonzheron', '1200.00', null],
    'remkomplekt pola' => ['remkomplekt-pola', '2190.00', null],
    'tortsevaia zaglushka' => ['tortsevaia-zaglushka', '600.00', null],
    'usilitel soedinitel porogov' => ['usilitel/soedinitel-porogov', '900.00', null],
]);

test('unmapped imported PartType stays safely unpriced and warns once per run', function (): void {
    Queue::fake();
    $run = referencePriceImportRun();
    $category = ProductCategory::query()
        ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova')
        ->firstOrFail();
    $partType = PartType::factory()->forCategory($category)->create(['title' => 'Неизвестная ценовая деталь']);

    foreach ([VehicleGeneration::factory()->create(), VehicleGeneration::factory()->create()] as $generation) {
        $product = app(ImportProductFactory::class)->createOrUpdateFromCell(
            run: $run,
            generation: $generation,
            partType: $partType,
            storeCategory: $category,
            detailHeader: ['index' => 6, 'group' => null, 'title' => $partType->title, 'category_title' => $category->title],
            cellValue: '',
        );

        expect($product->price)->toBeNull()
            ->and($product->old_price)->toBeNull()
            ->and($product->defaultVariant()->firstOrFail()->price)->toEqual('0.00');
    }

    expect(ImportLog::query()
        ->where('import_run_id', $run->getKey())
        ->where('message', 'Справочная цена для типа детали не найдена')
        ->count())->toBe(1);
});

test('ordinary repeated import never backfills an existing zero price or overwrites manual price fields', function (): void {
    Queue::fake();
    $partType = PartType::query()->where('full_slug', 'porog')->firstOrFail();
    $category = $partType->productCategory()->firstOrFail();
    $generation = VehicleGeneration::factory()->create();
    $factory = app(ImportProductFactory::class);

    $product = $factory->createOrUpdateFromCell(
        run: referencePriceImportRun(),
        generation: $generation,
        partType: $partType,
        storeCategory: $category,
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => $category->title],
        cellValue: '',
    );
    $variant = $product->defaultVariant()->firstOrFail();

    $product->forceFill(['price' => 0, 'old_price' => 5555.55])->saveQuietly();
    $variant->forceFill(['price' => 0, 'old_price' => 6666.66])->saveQuietly();

    $factory->createOrUpdateFromCell(
        run: referencePriceImportRun(),
        generation: $generation,
        partType: $partType,
        storeCategory: $category,
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => $category->title],
        cellValue: '',
    );

    expect($product->fresh()->price)->toEqual('0.00')
        ->and($product->fresh()->old_price)->toEqual('5555.55')
        ->and($variant->fresh()->price)->toEqual('0.00')
        ->and($variant->fresh()->old_price)->toEqual('6666.66');
});

test('backfill is dry run by default applies only safe catalog candidates and is idempotent', function (): void {
    $needsPrice = referencePriceBackfillProduct('porog');
    $manualPositive = referencePriceBackfillProduct('porog', ['price' => 12345.67], ['price' => 7777.77]);
    $penka = referencePriceBackfillProduct('penka/bagazhnika');
    $customOldPrice = referencePriceBackfillProduct(
        'penka/bagazhnika',
        ['old_price' => 3333.33],
        ['old_price' => 4444.44],
    );
    $missingVariant = referencePriceBackfillProduct('porog', [], null);

    $manual = referencePriceBackfillProduct('porog', [
        'import_source' => null,
        'import_key' => null,
    ]);
    $otherSource = referencePriceBackfillProduct('porog', [
        'import_source' => 'supplier',
        'import_key' => 'supplier:test:'.strtolower((string) Str::ulid()),
    ]);

    $fallbackCategory = ProductCategory::query()
        ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova')
        ->firstOrFail();
    $unknownPartType = PartType::factory()->forCategory($fallbackCategory)->create(['title' => 'Неизвестная backfill деталь']);
    $unknown = Product::factory()->forCategory($fallbackCategory)->forPartType($unknownPartType)->create([
        'price' => null,
        'old_price' => null,
        'import_source' => 'catalog',
        'import_key' => 'catalog:unknown:'.strtolower((string) Str::ulid()),
    ]);
    $unknownVariant = ProductVariant::factory()->forProduct($unknown)->default()->create(['price' => 0]);

    $this->artisan('catalog:backfill-import-prices')
        ->expectsOutputToContain('mode=DRY-RUN')
        ->expectsOutputToContain('catalog_products_scanned=6')
        ->expectsOutputToContain('mapped_products=5')
        ->expectsOutputToContain('unmapped_products=1')
        ->expectsOutputToContain('product_prices_to_update=4')
        ->expectsOutputToContain('variant_prices_to_update=3')
        ->expectsOutputToContain('old_prices_to_update=2')
        ->expectsOutputToContain('positive_product_prices_preserved=1')
        ->expectsOutputToContain('positive_variant_prices_preserved=1')
        ->expectsOutputToContain('missing_default_variants=1')
        ->expectsOutputToContain('Dry-run завершён. База данных не изменялась.')
        ->assertExitCode(0);

    expect($needsPrice->fresh()->price)->toBeNull()
        ->and($needsPrice->defaultVariant()->firstOrFail()->price)->toEqual('0.00')
        ->and($penka->fresh()->old_price)->toBeNull();

    $this->artisan('catalog:backfill-import-prices --apply')
        ->expectsOutputToContain('mode=APPLY')
        ->expectsOutputToContain('Reference price backfill завершён.')
        ->assertExitCode(0);

    expect($needsPrice->fresh()->price)->toEqual('1790.00')
        ->and($needsPrice->defaultVariant()->firstOrFail()->price)->toEqual('1790.00')
        ->and($manualPositive->fresh()->price)->toEqual('12345.67')
        ->and($manualPositive->defaultVariant()->firstOrFail()->price)->toEqual('7777.77')
        ->and($penka->fresh()->price)->toEqual('2090.00')
        ->and($penka->fresh()->old_price)->toEqual('2500.00')
        ->and($penka->defaultVariant()->firstOrFail()->price)->toEqual('2090.00')
        ->and($penka->defaultVariant()->firstOrFail()->old_price)->toEqual('2500.00')
        ->and($customOldPrice->fresh()->price)->toEqual('2090.00')
        ->and($customOldPrice->fresh()->old_price)->toEqual('3333.33')
        ->and($customOldPrice->defaultVariant()->firstOrFail()->price)->toEqual('2090.00')
        ->and($customOldPrice->defaultVariant()->firstOrFail()->old_price)->toEqual('4444.44')
        ->and($missingVariant->fresh()->price)->toEqual('1790.00')
        ->and($missingVariant->defaultVariant()->exists())->toBeFalse()
        ->and($manual->fresh()->price)->toBeNull()
        ->and($manual->defaultVariant()->firstOrFail()->price)->toEqual('0.00')
        ->and($otherSource->fresh()->price)->toBeNull()
        ->and($otherSource->defaultVariant()->firstOrFail()->price)->toEqual('0.00')
        ->and($unknown->fresh()->price)->toBeNull()
        ->and($unknownVariant->fresh()->price)->toEqual('0.00');

    $this->artisan('catalog:backfill-import-prices')
        ->expectsOutputToContain('product_prices_to_update=0')
        ->expectsOutputToContain('variant_prices_to_update=0')
        ->expectsOutputToContain('old_prices_to_update=0')
        ->expectsOutputToContain('missing_default_variants=1')
        ->assertExitCode(0);

    $this->artisan('catalog:backfill-import-prices --apply')
        ->expectsOutputToContain('product_prices_to_update=0')
        ->expectsOutputToContain('variant_prices_to_update=0')
        ->expectsOutputToContain('old_prices_to_update=0')
        ->assertExitCode(0);

    expect($needsPrice->fresh()->price)->toEqual('1790.00')
        ->and($needsPrice->defaultVariant()->firstOrFail()->price)->toEqual('1790.00')
        ->and($penka->fresh()->old_price)->toEqual('2500.00')
        ->and($customOldPrice->fresh()->old_price)->toEqual('3333.33');
});

test('reference priced import becomes sellable while unmapped import keeps zero price safety', function (): void {
    Queue::fake();
    $run = referencePriceImportRun();
    $porog = PartType::query()->where('full_slug', 'porog')->firstOrFail();
    $porogProduct = app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: $run,
        generation: VehicleGeneration::factory()->create(),
        partType: $porog,
        storeCategory: $porog->productCategory()->firstOrFail(),
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => 'Порог'],
        cellValue: '',
    );
    $pricedVariant = $porogProduct->defaultVariant()->firstOrFail();
    $availability = app(StorefrontProductAvailability::class);
    $card = ProductCardViewModel::fromProduct($porogProduct->fresh()->load('variants'));
    $cart = Cart::factory()->create();
    $cartItem = app(CartManager::class)->addItem(referencePriceCartRequest($cart), $pricedVariant->getKey());

    expect($availability->hasSellablePrice($pricedVariant))->toBeTrue()
        ->and($card->price)->toBe('1 790')
        ->and($card->priceLabel)->toBe('1 790 ₽')
        ->and($porogProduct->variants()->count())->toBe(24)
        ->and($card->variantId)->toBeNull()
        ->and($cartItem->price_snapshot)->toEqual('1790.00');

    $fallbackCategory = ProductCategory::query()
        ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova')
        ->firstOrFail();
    $unknownPartType = PartType::factory()->forCategory($fallbackCategory)->create(['title' => 'Без справочной цены']);
    $unpricedProduct = app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: $run,
        generation: VehicleGeneration::factory()->create(),
        partType: $unknownPartType,
        storeCategory: $fallbackCategory,
        detailHeader: ['index' => 7, 'group' => null, 'title' => $unknownPartType->title, 'category_title' => $fallbackCategory->title],
        cellValue: '',
    );
    $unpricedVariant = $unpricedProduct->defaultVariant()->firstOrFail();
    $unpricedCard = ProductCardViewModel::fromProduct($unpricedProduct->fresh()->load('variants'));

    expect($availability->hasSellablePrice($unpricedVariant))->toBeFalse()
        ->and($unpricedCard->priceLabel)->toBe('Цена по запросу')
        ->and($unpricedCard->variantId)->toBeNull()
        ->and(fn () => app(CartManager::class)->addItem(referencePriceCartRequest(Cart::factory()->create()), $unpricedVariant->getKey()))
        ->toThrow(ValidationException::class, CartManager::PRICE_UNAVAILABLE_MESSAGE);
});
