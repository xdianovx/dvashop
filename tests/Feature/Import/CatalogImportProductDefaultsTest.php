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
    app(\App\Services\Catalog\CatalogConfigurations::class)
        ->install(base_path('tests/Fixtures/catalog-configurations.csv'));
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

test('new imported product receives only the CSV configurations and their distinct prices', function (): void {
    $product = p5oImportProduct()->fresh();
    $matrix = app(\App\Services\Catalog\CatalogConfigurations::class);
    $rows = $matrix->rows($product->part_type_id);
    $variants = $product->variants()->with('optionValues.group')->get();

    expect($rows)->not->toBeEmpty()
        ->and($product->configuration_managed)->toBeTrue()
        ->and($product->optionTemplate?->slug)->toStartWith('matrix_')
        ->and($variants)->toHaveCount($rows->count())
        ->and($variants->where('is_default', true))->toHaveCount(1)
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->is_active))->toBeTrue()
        ->and($variants->pluck('price')->unique()->count())->toBeGreaterThan(1)
        ->and($product->price)->toBe($variants->firstWhere('is_default', true)?->price);

    foreach ($rows as $row) {
        $variant = $variants->first(fn (ProductVariant $variant): bool => $matrix->signature($variant) === $row->signature);
        expect($variant)->not->toBeNull()
            ->and($variant->price)->toBe($row->price)
            ->and($variant->catalog_configuration_id)->toBe($row->id);
    }
    expect($product->description)->toBe('Деталь «Порог» для Alfa Romeo 33. Доступные варианты и цены указаны на странице товара.')
        ->and($product->characteristics()->count())->toBe(0);
});

test('hierarchical part type gets a natural non-marketing description', function (): void {
    $product = p5oImportProduct('arka/zadniaia');
    expect($product->description)->toStartWith('Деталь «Арка задняя» для Alfa Romeo 33.')
        ->and($product->description)->not->toContain('сертификат')
        ->and($product->description)->not->toContain('ГОСТ');
});

test('a two-row CSV group generates exactly two offers and no invented old prices', function (): void {
    $product = p5oImportProduct('penka/bagazhnika');
    $rows = app(\App\Services\Catalog\CatalogConfigurations::class)->rows($product->part_type_id);
    $variants = $product->variants()->get();

    expect($rows)->toHaveCount(2)
        ->and($variants)->toHaveCount(2)
        ->and($variants->pluck('price')->sort()->values()->all())
        ->toBe($rows->pluck('price')->sort()->values()->all())
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->old_price === null))->toBeTrue();
});

test('repeated import preserves administrator fields but restores the CSV price', function (): void {
    $generation = p5oGeneration();
    $product = p5oImportProduct('porog', $generation)->fresh();
    $variant = $product->variants()->where('is_default', false)->firstOrFail();
    $configurationPrice = $variant->configuration()->firstOrFail()->price;
    $characteristic = ProductCharacteristic::factory()->for($product, 'product')->create([
        'name' => 'Материал', 'value' => 'Ручная характеристика',
        'source_type' => ProductCharacteristic::SOURCE_MANUAL,
    ]);
    app(ProductAdminService::class)->save($product, ['description' => 'Ручное описание администратора']);
    app(ProductVariantAdminService::class)->save($variant, [
        'sku' => 'MANUAL-CSV-VARIANT', 'stock_quantity' => 17,
        'stock_status' => StockStatus::OutOfStock,
    ]);
    app(ProductVariantAdminService::class)->setDefault($product, $variant);
    // A historical override is represented by a direct DB change in the fixture,
    // since current admin writes to CSV-managed variant prices are intentionally blocked.
    \Illuminate\Support\Facades\DB::table('product_variants')->where('id', $variant->id)
        ->update(['price' => '7777.77', 'price_mode' => 'individual']);
    $idsBefore = $product->variants()->orderBy('id')->pluck('id')->all();

    p5oImportProduct('porog', $generation);

    expect($product->fresh()->description)->toBe('Ручное описание администратора')
        ->and($characteristic->fresh()->value)->toBe('Ручная характеристика')
        ->and($variant->fresh()->sku)->toBe('MANUAL-CSV-VARIANT')
        ->and($variant->stock_quantity)->toBe(17)
        ->and($variant->stock_status)->toBe(StockStatus::OutOfStock)
        ->and($variant->price)->toBe($configurationPrice)
        ->and($variant->price_mode)->toBe('inherited')
        ->and($variant->fresh()->is_default)->toBeTrue()
        ->and($product->variants()->orderBy('id')->pluck('id')->all())->toBe($idsBefore)
        ->and(\Illuminate\Support\Facades\DB::table('catalog_matrix_price_history')->where('product_variant_id', $variant->id)
            ->where('old_price', '7777.77')->count())->toBe(1);
});

test('a second unchanged import does not add variants or price history', function (): void {
    $generation = p5oGeneration();
    $product = p5oImportProduct('porog', $generation);
    $variantsBefore = $product->variants()->orderBy('id')->pluck('id')->all();
    $historyBefore = \Illuminate\Support\Facades\DB::table('catalog_matrix_price_history')
        ->where('product_id', $product->id)->count();

    p5oImportProduct('porog', $generation);

    expect($product->fresh()->variants()->orderBy('id')->pluck('id')->all())->toBe($variantsBefore)
        ->and(\Illuminate\Support\Facades\DB::table('catalog_matrix_price_history')
            ->where('product_id', $product->id)->count())->toBe($historyBefore);
});

test('without an installed active CSV group import fails without creating a product', function (): void {
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    \App\Models\CatalogConfiguration::where('part_type_id', $type->id)->update(['is_active' => false]);
    app(\App\Services\Catalog\CatalogConfigurations::class)->clear();
    expect(fn () => p5oImportProduct())->toThrow(\Illuminate\Validation\ValidationException::class);
    p5oAssertNoPartialProduct();
});

test('removing the unrelated old default template does not disable a valid CSV matrix', function (): void {
    ProductOptionTemplate::where('slug', 'default_auto_part')->delete();
    $product = p5oImportProduct();
    $expected = app(\App\Services\Catalog\CatalogConfigurations::class)->rows($product->part_type_id)->count();
    expect($product->variants()->count())->toBe($expected)
        ->and($product->optionTemplate?->slug)->toStartWith('matrix_');
});

test('inactive or inconsistent CSV template blocks a new import without partial product state', function (string $kind): void {
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    $template = app(\App\Services\Catalog\CatalogConfigurations::class)->template($type->id);
    if ($kind === 'inactive') {
        $template->update(['is_active' => false]);
    } else {
        $template->update(['slug' => 'non_matrix_template']);
    }
    expect(fn () => p5oImportProduct())->toThrow(\Illuminate\Validation\ValidationException::class);
    p5oAssertNoPartialProduct();
})->with(['inactive', 'wrong_slug']);

test('an inactive option required by the CSV blocks the import before creating products', function (): void {
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    $row = app(\App\Services\Catalog\CatalogConfigurations::class)->rows($type->id)->firstOrFail();
    $group = ProductOptionGroup::where('code', array_key_first($row->options))->firstOrFail();
    ProductOptionValue::where('product_option_group_id', $group->id)
        ->where('code', $row->options[$group->code])->update(['is_active' => false]);

    expect(fn () => p5oImportProduct())->toThrow(\Illuminate\Validation\ValidationException::class);
    p5oAssertNoPartialProduct();
});

test('a missing mapping for an allowed CSV option blocks the import before creating products', function (): void {
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    $row = app(\App\Services\Catalog\CatalogConfigurations::class)->rows($type->id)->firstOrFail();
    $group = ProductOptionGroup::where('code', array_key_first($row->options))->firstOrFail();
    $value = ProductOptionValue::where('product_option_group_id', $group->id)
        ->where('code', $row->options[$group->code])->firstOrFail();
    ProductOptionTemplateItem::where('product_option_template_id', $row->product_option_template_id)
        ->where('product_option_group_id', $group->id)
        ->where('product_option_value_id', $value->id)->delete();

    expect(fn () => p5oImportProduct())->toThrow(\Illuminate\Validation\ValidationException::class);
    p5oAssertNoPartialProduct();
});

test('extra template inventory cannot invent a configuration not present in CSV', function (): void {
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    $template = app(\App\Services\Catalog\CatalogConfigurations::class)->template($type->id);
    $group = ProductOptionGroup::factory()->create([
        'title' => 'Extra group', 'slug' => 'unused-extra', 'code' => 'unused_extra',
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART, 'is_active' => true,
    ]);
    $value = ProductOptionValue::factory()->forGroup($group)->create([
        'title' => 'Extra value', 'slug' => 'unused-extra-value', 'code' => 'unused_extra_value', 'is_active' => true,
    ]);
    ProductOptionTemplateItem::create([
        'product_option_template_id' => $template->id,
        'product_option_group_id' => $group->id,
        'product_option_value_id' => $value->id, 'position' => 999,
    ]);

    $product = p5oImportProduct();
    expect($product->variants()->count())->toBe(app(\App\Services\Catalog\CatalogConfigurations::class)->rows($type->id)->count())
        ->and($product->variants()->whereHas('optionValues.group', fn ($query) => $query->where('code', 'unused_extra'))->exists())->toBeFalse();
});

test('an existing legacy import product without a template adopts CSV variants without creating a second product', function (): void {
    $generation = p5oGeneration();
    $type = PartType::where('full_slug', 'porog')->firstOrFail();
    $category = $type->productCategory()->firstOrFail();
    $factory = app(ImportProductFactory::class);
    $title = $factory->productTitle($type, $generation);
    $key = $factory->importKey($generation, $type, 'catalog');
    $legacy = Product::factory()->forCategory($category)->forPartType($type)->create([
        'product_type' => ProductType::AutoPart, 'product_option_template_id' => null,
        'title' => $title, 'slug' => $factory->stableSlug($generation, $type, 'catalog', $title),
        'status' => ProductStatus::Active, 'import_key' => $key,
        'import_source' => 'catalog', 'last_import_run_id' => 'legacy-run',
    ]);
    // Inherited pre-matrix offer, intentionally created through SQL: the modern model
    // forbids creating a new unmanaged offer on an import-owned product.
    $legacyVariantId = \Illuminate\Support\Facades\DB::table('product_variants')->insertGetId([
        'product_id' => $legacy->id, 'title' => 'Historical offer',
        'price' => '999.00', 'price_mode' => 'individual', 'is_default' => true,
        'is_active' => true, 'stock_status' => StockStatus::InStock->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $restored = p5oImportProduct('porog', $generation);
    $count = app(\App\Services\Catalog\CatalogConfigurations::class)->rows($type->id)->count();

    expect($restored->id)->toBe($legacy->id)
        ->and(Product::where('import_key', $key)->count())->toBe(1)
        ->and($restored->variants()->where('is_active', true)->count())->toBe($count)
        ->and(ProductVariant::findOrFail($legacyVariantId)->is_active)->toBeFalse();
});

test('storefront exposes only CSV-approved configurations and the selected price', function (): void {
    $product = p5oImportProduct()->fresh();
    // Snapshot imports are published only after their finalization; simulate that step.
    \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)
        ->update(['status' => ProductStatus::Active->value, 'import_present' => true]);
    $response = $this->get(route('products.show', $product->slug));
    $response->assertOk()->assertSee('data-variant-matrix', false);
    $html = $response->getContent();
    preg_match('#<script type="application/json" data-variant-matrix>(.*?)</script>#s', $html, $matches);
    $matrix = json_decode($matches[1] ?? '[]', true, flags: JSON_THROW_ON_ERROR);

    expect($matrix)->toHaveCount(app(\App\Services\Catalog\CatalogConfigurations::class)->rows($product->part_type_id)->count());
    $default = $product->fresh()->defaultVariant()->firstOrFail();
    expect($default->price)->toBe($default->configuration()->firstOrFail()->price);
});
