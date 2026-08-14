<?php

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\ProductAdminService;
use App\Services\Catalog\ProductVariantAdminService;
use App\Services\Import\ImportProductFactory;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(PartTypeSeeder::class);
    $this->seed(ProductOptionSeeder::class);
    Queue::fake();
});

function p5pImportRun(array $overrides = []): ImportRun
{
    return ImportRun::factory()->create(array_merge([
        'type' => 'catalog',
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 1,
    ], $overrides));
}

function p5pGeneration(): VehicleGeneration
{
    $make = VehicleMake::factory()->create([
        'title' => 'Lifecycle Make',
        'slug' => 'lifecycle-make',
        'norm_key' => 'lifecycle-make',
    ]);
    $model = VehicleModel::factory()->forMake($make)->create([
        'title' => 'Lifecycle Model',
        'slug' => 'lifecycle-model',
        'norm_key' => 'lifecycle-model',
    ]);

    return VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'Lifecycle Generation',
        'slug' => 'lifecycle-generation',
        'norm_key' => 'lifecycle-generation',
    ])->load('model.make');
}

function p5pImportProduct(ImportRun $run, VehicleGeneration $generation): Product
{
    $partType = PartType::query()->where('full_slug', 'porog')->firstOrFail();
    $category = $partType->productCategory()->firstOrFail();

    return app(ImportProductFactory::class)->createOrUpdateFromCell(
        run: $run,
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

/** @return array{variants:list<int>,mappings:list<int>,characteristics:list<int>,fitments:list<int>,images:list<int>} */
function p5pRelationSnapshot(Product $product): array
{
    $variantIds = $product->variants()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

    return [
        'variants' => $variantIds,
        'mappings' => ProductVariantOptionValue::query()->whereIn('product_variant_id', $variantIds)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        'characteristics' => $product->characteristics()->reorder()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        'fitments' => $product->fitments()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        'images' => $product->images()->reorder()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
    ];
}

test('full snapshot archives a missing imported Product and later reactivates the same record without losing admin data or relations', function (): void {
    $generation = p5pGeneration();
    $runA = p5pImportRun();
    $product = p5pImportProduct($runA, $generation)->fresh();
    $productId = (int) $product->getKey();
    $importKey = $product->import_key;
    $characteristic = $product->characteristics()->where('name', 'Материал')->firstOrFail();
    $alternateDefault = $product->variants()->where('is_default', false)->orderByDesc('id')->firstOrFail();

    $product = app(ProductAdminService::class)->save($product, [
        'sku' => 'LIFECYCLE-MANUAL-SKU',
        'price' => 12345.67,
        'old_price' => 15000,
        'description' => 'Ручное lifecycle описание',
    ]);
    $characteristic->update(['value' => 'Ручная lifecycle характеристика']);
    $alternateDefault = app(ProductVariantAdminService::class)->save($alternateDefault, [
        'sku' => 'LIFECYCLE-VARIANT-SKU',
        'price' => 7777.77,
        'old_price' => 8888.88,
        'stock_quantity' => 17,
        'stock_status' => StockStatus::OutOfStock,
    ]);
    app(ProductVariantAdminService::class)->setDefault($product, $alternateDefault);
    $manualImage = ProductImage::factory()->forProduct($product)->create([
        'path' => 'https://cdn.example.test/manual-lifecycle.jpg',
        'source_type' => ProductImage::SOURCE_MANUAL,
    ]);

    $before = p5pRelationSnapshot($product->fresh());

    $runB = p5pImportRun();
    expect(app(ImportProductFactory::class)->archiveMissingProducts($runB))->toBe(1);

    $archived = Product::withTrashed()->where('import_key', $importKey)->firstOrFail();
    expect((int) $archived->getKey())->toBe($productId)
        ->and($archived->status)->toBe(ProductStatus::Archived)
        ->and($archived->trashed())->toBeFalse()
        ->and($archived->sku)->toBe('LIFECYCLE-MANUAL-SKU')
        ->and($archived->price)->toEqual('12345.67')
        ->and($archived->old_price)->toEqual('15000.00')
        ->and($archived->description)->toBe('Ручное lifecycle описание')
        ->and($characteristic->fresh()->value)->toBe('Ручная lifecycle характеристика')
        ->and($alternateDefault->fresh()->is_default)->toBeTrue()
        ->and($manualImage->fresh())->toBeInstanceOf(ProductImage::class)
        ->and(p5pRelationSnapshot($archived))->toBe($before);

    $runC = p5pImportRun();
    $reactivated = p5pImportProduct($runC, $generation)->fresh();

    expect((int) $reactivated->getKey())->toBe($productId)
        ->and(Product::withTrashed()->where('import_key', $importKey)->count())->toBe(1)
        ->and($reactivated->status)->toBe(ProductStatus::Active)
        ->and($reactivated->import_key)->toBe($importKey)
        ->and($reactivated->last_import_run_id)->toBe((string) $runC->getKey())
        ->and($reactivated->sku)->toBe('LIFECYCLE-MANUAL-SKU')
        ->and($reactivated->price)->toEqual('12345.67')
        ->and($reactivated->old_price)->toEqual('15000.00')
        ->and($reactivated->description)->toBe('Ручное lifecycle описание')
        ->and($characteristic->fresh()->value)->toBe('Ручная lifecycle характеристика')
        ->and($alternateDefault->fresh()->sku)->toBe('LIFECYCLE-VARIANT-SKU')
        ->and($alternateDefault->fresh()->price)->toEqual('7777.77')
        ->and($alternateDefault->fresh()->old_price)->toEqual('8888.88')
        ->and($alternateDefault->fresh()->stock_quantity)->toBe(17)
        ->and($alternateDefault->fresh()->stock_status)->toBe(StockStatus::OutOfStock)
        ->and($alternateDefault->fresh()->is_default)->toBeTrue()
        ->and(p5pRelationSnapshot($reactivated))->toBe($before)
        ->and($reactivated->variants()->count())->toBe(24)
        ->and($reactivated->characteristics()->count())->toBe(4)
        ->and($reactivated->fitments()->count())->toBe(1);
});

test('soft deleted catalog Product is restored as the same existing record without duplicate relations or rerunning first create defaults', function (): void {
    $generation = p5pGeneration();
    $product = p5pImportProduct(p5pImportRun(), $generation)->fresh();
    $productId = (int) $product->getKey();
    $importKey = $product->import_key;
    $product = app(ProductAdminService::class)->save($product, [
        'sku' => 'RESTORED-MANUAL-SKU',
        'description' => 'Описание до soft delete',
    ]);
    ProductImage::factory()->forProduct($product)->create([
        'path' => 'https://cdn.example.test/restore-manual.jpg',
        'source_type' => ProductImage::SOURCE_MANUAL,
    ]);
    $before = p5pRelationSnapshot($product);

    $product->delete();
    expect(Product::query()->where('import_key', $importKey)->exists())->toBeFalse()
        ->and(Product::withTrashed()->where('import_key', $importKey)->count())->toBe(1);

    $restored = p5pImportProduct(p5pImportRun(), $generation)->fresh();

    expect((int) $restored->getKey())->toBe($productId)
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->status)->toBe(ProductStatus::Active)
        ->and(Product::withTrashed()->where('import_key', $importKey)->count())->toBe(1)
        ->and($restored->sku)->toBe('RESTORED-MANUAL-SKU')
        ->and($restored->description)->toBe('Описание до soft delete')
        ->and(p5pRelationSnapshot($restored))->toBe($before)
        ->and($restored->variants()->count())->toBe(24)
        ->and($restored->characteristics()->count())->toBe(4)
        ->and($restored->fitments()->count())->toBe(1);
});

test('soft deleted legacy catalog Product restores without receiving PROMPT 5O defaults', function (): void {
    $generation = p5pGeneration();
    $partType = PartType::query()->where('full_slug', 'porog')->firstOrFail();
    $category = $partType->productCategory()->firstOrFail();
    $factory = app(ImportProductFactory::class);
    $title = $factory->productTitle($partType, $generation);
    $importKey = $factory->importKey($generation, $partType, 'catalog');
    $legacy = Product::factory()->forCategory($category)->forPartType($partType)->create([
        'product_type' => ProductType::AutoPart,
        'product_option_template_id' => null,
        'title' => $title,
        'slug' => $factory->stableSlug($generation, $partType, 'catalog', $title),
        'status' => ProductStatus::Active,
        'description' => null,
        'import_key' => $importKey,
        'import_source' => 'catalog',
        'last_import_run_id' => 'legacy-run',
    ]);
    $variant = ProductVariant::factory()->forProduct($legacy)->default()->create([
        'price' => 0,
        'stock_status' => StockStatus::InStock,
    ]);
    ProductFitment::factory()->forProduct($legacy)->forVehicleGeneration($generation)->create();
    $legacyId = (int) $legacy->getKey();
    $variantId = (int) $variant->getKey();

    $legacy->delete();
    $restored = p5pImportProduct(p5pImportRun(), $generation)->fresh();

    expect((int) $restored->getKey())->toBe($legacyId)
        ->and(Product::withTrashed()->where('import_key', $importKey)->count())->toBe(1)
        ->and($restored->product_option_template_id)->toBeNull()
        ->and($restored->description)->toBeNull()
        ->and($restored->characteristics()->count())->toBe(0)
        ->and($restored->variants()->count())->toBe(1)
        ->and((int) $restored->variants()->firstOrFail()->getKey())->toBe($variantId)
        ->and(ProductVariantOptionValue::query()->where('product_variant_id', $variantId)->count())->toBe(0)
        ->and($restored->fitments()->count())->toBe(1);
});

test('snapshot archive remains scoped to catalog imported keyed Products and never soft deletes rows', function (): void {
    $catalog = Product::factory()->create([
        'import_key' => 'catalog:missing',
        'import_source' => 'catalog',
        'last_import_run_id' => 'old',
        'status' => ProductStatus::Active,
    ]);
    $manual = Product::factory()->create([
        'import_key' => null,
        'import_source' => null,
        'last_import_run_id' => null,
        'status' => ProductStatus::Active,
    ]);
    $other = Product::factory()->create([
        'import_key' => 'other:missing',
        'import_source' => 'other',
        'last_import_run_id' => 'old',
        'status' => ProductStatus::Active,
    ]);

    $run = p5pImportRun();
    expect(app(ImportProductFactory::class)->archiveMissingProducts($run))->toBe(1)
        ->and($catalog->fresh()->status)->toBe(ProductStatus::Archived)
        ->and($catalog->fresh()->trashed())->toBeFalse()
        ->and($manual->fresh()->status)->toBe(ProductStatus::Active)
        ->and($other->fresh()->status)->toBe(ProductStatus::Active);
});
