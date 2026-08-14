<?php

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCharacteristic;
use App\Models\ProductFitment;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\CartManager;
use App\Services\Catalog\ProductAdminService;
use App\Services\Catalog\ProductVariantAdminService;
use App\Services\Import\ImportProductFactory;
use App\Services\StorefrontProductAvailability;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(PartTypeSeeder::class);
    $this->seed(ProductOptionSeeder::class);
    Queue::fake();
});

function p5oImportRun(): ImportRun
{
    return ImportRun::factory()->create([
        'type' => 'catalog',
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 1,
    ]);
}

function p5oGeneration(string $makeTitle = 'Alfa Romeo', string $modelTitle = '33'): VehicleGeneration
{
    $make = VehicleMake::factory()->create([
        'title' => $makeTitle,
        'slug' => str($makeTitle)->slug()->toString(),
        'norm_key' => str($makeTitle)->slug()->toString(),
    ]);
    $model = VehicleModel::factory()->forMake($make)->create([
        'title' => $modelTitle,
        'slug' => str($modelTitle)->slug()->toString(),
        'norm_key' => str($modelTitle)->slug()->toString(),
    ]);

    return VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'I',
        'slug' => 'i',
        'norm_key' => 'i',
        'years_label' => '1990–1995',
        'body' => 'седан',
    ])->load('model.make');
}

function p5oImportProduct(string $fullSlug = 'porog', ?VehicleGeneration $generation = null): Product
{
    $generation ??= p5oGeneration();
    $partType = PartType::query()->where('full_slug', $fullSlug)->firstOrFail();
    $category = $partType->productCategory()->firstOrFail();

    return app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: p5oImportRun(),
        generation: $generation,
        partType: $partType,
        storeCategory: $category,
        detailHeader: [
            'index' => 6,
            'group' => null,
            'title' => $partType->title,
            'category_title' => $category->title,
        ],
        cellValue: '',
    );
}

/** @return array<string, string> */
function p5oVariantSignature(ProductVariant $variant): array
{
    $variant->loadMissing('optionValues.group');

    return $variant->optionValues
        ->mapWithKeys(fn (ProductOptionValue $value): array => [
            (string) $value->group?->code => (string) $value->code,
        ])
        ->sortKeys()
        ->all();
}

function p5oFindVariant(Product $product, array $signature): ProductVariant
{
    return $product->variants()
        ->with('optionValues.group')
        ->get()
        ->first(fn (ProductVariant $variant): bool => p5oVariantSignature($variant) === $signature)
        ?? throw new LogicException('Test variant signature was not generated.');
}

function p5oAssertNoPartialProduct(): void
{
    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0)
        ->and(ProductVariantOptionValue::query()->count())->toBe(0)
        ->and(ProductCharacteristic::query()->count())->toBe(0)
        ->and(ProductFitment::query()->count())->toBe(0);
}

test('new catalog Product gets canonical template 24 real variants 96 option mappings description and four characteristics', function (): void {
    $product = p5oImportProduct()->fresh();
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $variants = $product->variants()->with('optionValues.group')->orderBy('id')->get();
    $default = $variants->firstWhere('is_default', true);
    $inventory = $template->items()
        ->with(['group', 'value'])
        ->get()
        ->filter(fn (ProductOptionTemplateItem $item): bool => (bool) $item->group?->is_active && (bool) $item->value?->is_active)
        ->groupBy(fn (ProductOptionTemplateItem $item): string => (string) $item->group?->code)
        ->map(fn ($items) => $items->pluck('value.code')->sort()->values()->all())
        ->sortKeys()
        ->all();

    expect($inventory)->toBe([
        'material' => ['cold_rolled', 'galvanized'],
        'position' => ['both', 'left', 'right'],
        'profile' => ['full', 'lower'],
        'thickness' => ['1_5mm', '1mm'],
    ])
        ->and($product->product_option_template_id)->toBe($template->getKey())
        ->and($product->sku)->toBeNull()
        ->and($product->price)->toEqual('1790.00')
        ->and($variants)->toHaveCount(24)
        ->and($variants->where('is_default', true))->toHaveCount(1)
        ->and($variants->whereNotNull('sku'))->toHaveCount(0)
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->optionValues->count() === 4))->toBeTrue()
        ->and(ProductVariantOptionValue::query()->whereIn('product_variant_id', $variants->pluck('id'))->count())->toBe(96)
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->price === '1790.00'))->toBeTrue()
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->old_price === null))->toBeTrue()
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->stock_status === StockStatus::InStock))->toBeTrue()
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->stock_quantity === null))->toBeTrue()
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->is_active))->toBeTrue()
        ->and($default)->toBeInstanceOf(ProductVariant::class)
        ->and(p5oVariantSignature($default))->toBe([
            'material' => 'galvanized',
            'position' => 'left',
            'profile' => 'full',
            'thickness' => '1mm',
        ]);

    expect($product->description)
        ->toStartWith('Ремкомплект «Порог» для Alfa Romeo 33 предназначен')
        ->toContain('запас по длине 5 см')
        ->toContain('стали ГОСТ 19904-90')
        ->toContain('сертификат РосТест №РО30-4539');

    expect($product->characteristics()->get(['name', 'value', 'unit', 'source_type', 'is_visible', 'position'])->map->toArray()->all())
        ->toBe([
            ['name' => 'Марка', 'value' => 'Автопороги.ру', 'unit' => null, 'source_type' => ProductCharacteristic::SOURCE_IMPORT, 'is_visible' => true, 'position' => 10],
            ['name' => 'Производство', 'value' => 'Россия', 'unit' => null, 'source_type' => ProductCharacteristic::SOURCE_IMPORT, 'is_visible' => true, 'position' => 20],
            ['name' => 'Материал', 'value' => 'Сталь ГОСТ 19904-90', 'unit' => null, 'source_type' => ProductCharacteristic::SOURCE_IMPORT, 'is_visible' => true, 'position' => 30],
            ['name' => 'Сертификат', 'value' => '№0098556', 'unit' => null, 'source_type' => ProductCharacteristic::SOURCE_IMPORT, 'is_visible' => true, 'position' => 40],
        ])
        ->and($product->characteristics()->where('name', 'Артикул')->exists())->toBeFalse();
});

test('hierarchical PartType uses a natural display title in the first create description', function (): void {
    $product = p5oImportProduct('arka/zadniaia');

    expect($product->description)->toStartWith('Ремкомплект «Арка задняя» для Alfa Romeo 33 предназначен');
});

test('penka bagazhnika reference old price is copied to every generated variant', function (): void {
    $product = p5oImportProduct('penka/bagazhnika');
    $variants = $product->variants()->get();

    expect($product->price)->toEqual('2090.00')
        ->and($product->old_price)->toEqual('2500.00')
        ->and($variants)->toHaveCount(24)
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->price === '2090.00'))->toBeTrue()
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->old_price === '2500.00'))->toBeTrue();
});

test('repeated import preserves admin owned description characteristics variants option mappings prices sku stock and selected default', function (): void {
    $generation = p5oGeneration();
    $product = p5oImportProduct('porog', $generation)->fresh();
    $manualCharacteristic = $product->characteristics()->where('name', 'Материал')->firstOrFail();
    $manualVariant = p5oFindVariant($product, [
        'material' => 'cold_rolled',
        'position' => 'right',
        'profile' => 'lower',
        'thickness' => '1_5mm',
    ]);

    $product = app(ProductAdminService::class)->save($product, ['description' => 'Ручное описание администратора']);
    $manualCharacteristic->update(['value' => 'Ручная характеристика']);
    $manualVariant = app(ProductVariantAdminService::class)->save($manualVariant, [
        'sku' => 'MANUAL-VARIANT-SKU-5O',
        'price' => 7777.77,
        'old_price' => 8888.88,
        'stock_quantity' => 42,
        'stock_status' => StockStatus::OutOfStock,
    ]);
    app(ProductVariantAdminService::class)->setDefault($product, $manualVariant);

    $beforeVariants = $product->variants()
        ->with('optionValues.group')
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->getKey() => p5oVariantSignature($variant)])
        ->all();
    $beforeMappings = ProductVariantOptionValue::query()
        ->whereIn('product_variant_id', array_keys($beforeVariants))
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $partType = $product->partType()->firstOrFail();
    $category = $product->category()->firstOrFail();
    app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: p5oImportRun(),
        generation: $generation,
        partType: $partType,
        storeCategory: $category,
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => $category->title],
        cellValue: '',
    );

    $afterVariants = $product->variants()
        ->with('optionValues.group')
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->getKey() => p5oVariantSignature($variant)])
        ->all();
    $afterMappings = ProductVariantOptionValue::query()
        ->whereIn('product_variant_id', array_keys($afterVariants))
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($product->fresh()->description)->toBe('Ручное описание администратора')
        ->and($manualCharacteristic->fresh()->value)->toBe('Ручная характеристика')
        ->and($product->fresh()->characteristics()->count())->toBe(4)
        ->and($manualVariant->fresh()->sku)->toBe('MANUAL-VARIANT-SKU-5O')
        ->and($manualVariant->fresh()->price)->toEqual('7777.77')
        ->and($manualVariant->fresh()->old_price)->toEqual('8888.88')
        ->and($manualVariant->fresh()->stock_quantity)->toBe(42)
        ->and($manualVariant->fresh()->stock_status)->toBe(StockStatus::OutOfStock)
        ->and($manualVariant->fresh()->is_default)->toBeTrue()
        ->and($afterVariants)->toBe($beforeVariants)
        ->and($afterMappings)->toBe($beforeMappings)
        ->and(count($afterVariants))->toBe(24)
        ->and(count($afterMappings))->toBe(96);
});

test('missing canonical default auto part template fails before a Product is created', function (): void {
    ProductOptionTemplate::query()->where('slug', 'default_auto_part')->delete();

    expect(fn () => p5oImportProduct())
        ->toThrow(LogicException::class, 'default_auto_part');

    p5oAssertNoPartialProduct();
});

test('inactive or incompatible canonical template fails before a Product is created', function (array $state): void {
    ProductOptionTemplate::query()->where('slug', 'default_auto_part')->update($state);

    expect(fn () => p5oImportProduct())
        ->toThrow(LogicException::class, 'default_auto_part');

    p5oAssertNoPartialProduct();
})->with([
    'inactive' => [['is_active' => false]],
    'incompatible' => [['applies_to' => ProductOptionGroup::APPLIES_GENERIC]],
]);

test('missing required canonical option value fails without partial Product state', function (): void {
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $right = ProductOptionValue::query()->whereHas('group', fn ($query) => $query->where('code', 'position'))
        ->where('code', 'right')
        ->firstOrFail();

    ProductOptionTemplateItem::query()
        ->where('product_option_template_id', $template->getKey())
        ->where('product_option_value_id', $right->getKey())
        ->delete();

    expect(fn () => p5oImportProduct())
        ->toThrow(LogicException::class, '9 активных');

    p5oAssertNoPartialProduct();
});

test('unexpected active option inventory cannot silently expand imported variants beyond 24', function (): void {
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $group = ProductOptionGroup::factory()->create([
        'title' => 'Лишняя группа',
        'slug' => 'unexpected-import-group',
        'code' => 'unexpected_import_group',
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'is_active' => true,
    ]);
    $value = ProductOptionValue::factory()->forGroup($group)->create([
        'title' => 'Лишнее значение',
        'slug' => 'unexpected-import-value',
        'code' => 'unexpected_import_value',
        'is_active' => true,
    ]);
    ProductOptionTemplateItem::query()->create([
        'product_option_template_id' => $template->getKey(),
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
        'position' => 999,
    ]);

    expect(fn () => p5oImportProduct())
        ->toThrow(LogicException::class, '9 активных');

    p5oAssertNoPartialProduct();
});

test('ordinary repeated import of a legacy catalog Product does not assign defaults or expand its single variant', function (): void {
    $generation = p5oGeneration();
    $partType = PartType::query()->where('full_slug', 'porog')->firstOrFail();
    $category = $partType->productCategory()->firstOrFail();
    $factory = app(ImportProductFactory::class);
    $title = $factory->productTitle($partType, $generation);
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create([
        'product_type' => ProductType::AutoPart,
        'product_option_template_id' => null,
        'title' => $title,
        'slug' => $factory->stableSlug($generation, $partType, 'catalog', $title),
        'status' => ProductStatus::Active,
        'description' => null,
        'import_key' => $factory->importKey($generation, $partType, 'catalog'),
        'import_source' => 'catalog',
        'last_import_run_id' => 'legacy-run',
    ]);
    ProductVariant::factory()->forProduct($product)->default()->create([
        'price' => 0,
        'stock_status' => StockStatus::InStock,
    ]);

    ProductOptionTemplate::query()->where('slug', 'default_auto_part')->delete();

    $factory->createOrUpdateFromCell(
        run: p5oImportRun(),
        generation: $generation,
        partType: $partType,
        storeCategory: $category,
        detailHeader: ['index' => 6, 'group' => null, 'title' => 'Порог', 'category_title' => $category->title],
        cellValue: '',
    );

    expect($product->fresh()->product_option_template_id)->toBeNull()
        ->and($product->fresh()->description)->toBeNull()
        ->and($product->fresh()->characteristics()->count())->toBe(0)
        ->and($product->fresh()->variants()->count())->toBe(1)
        ->and(ProductVariantOptionValue::query()->whereIn('product_variant_id', $product->variants()->pluck('id'))->count())->toBe(0);
});

test('storefront renders generated import options description characteristics hidden sku and a real 24 row variant matrix', function (): void {
    $product = p5oImportProduct()->fresh();
    $default = $product->defaultVariant()->with('optionValues.group')->firstOrFail();
    $response = $this->get(route('products.show', $product->slug));

    $response->assertOk()
        ->assertSee('Ремкомплект «Порог» для Alfa Romeo 33 предназначен')
        ->assertSee('Марка')
        ->assertSee('Автопороги.ру')
        ->assertSee('Производство')
        ->assertSee('Россия')
        ->assertSee('Сталь ГОСТ 19904-90')
        ->assertSee('№0098556')
        ->assertSee('Профиль:')
        ->assertSee('Полный')
        ->assertSee('Нижняя часть')
        ->assertSee('Положение:')
        ->assertSee('Левый')
        ->assertSee('Правый')
        ->assertSee('Левый + Правый')
        ->assertSee('Материал:')
        ->assertSee('Оцинковка')
        ->assertSee('Х/С сталь')
        ->assertSee('Толщина металла:')
        ->assertSee('1 мм')
        ->assertSee('1,5 мм')
        ->assertSee('data-variant-matrix', false);

    $html = $response->getContent();
    expect($html)->toMatch('/<p[^>]*data-selected-sku-row[^>]*hidden[^>]*>/')
        ->and($html)->toContain('data-selected-variant')
        ->and($html)->toContain('value="'.$default->getKey().'"');

    preg_match('#<script type="application/json" data-variant-matrix>(.*?)</script>#s', $html, $matches);
    $matrix = json_decode($matches[1] ?? '[]', true, flags: JSON_THROW_ON_ERROR);

    expect($matrix)->toHaveCount(24)
        ->and(p5oVariantSignature($default))->toBe([
            'material' => 'galvanized',
            'position' => 'left',
            'profile' => 'full',
            'thickness' => '1mm',
        ])
        ->and(app(StorefrontProductAvailability::class)->hasSellablePrice($default))->toBeTrue();

    $cart = Cart::factory()->create();
    $request = Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
    $cartItem = app(CartManager::class)->addItem($request, $default->getKey());

    expect($cartItem->product_variant_id)->toBe($default->getKey())
        ->and($cartItem->price_snapshot)->toEqual('1790.00');
});
