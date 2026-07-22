<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogPartTypeRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function legacy_category(string $title, string $slug, ?ProductCategory $parent = null): ProductCategory
{
    $factory = ProductCategory::factory();

    if ($parent instanceof ProductCategory) {
        $factory = $factory->forParent($parent);
    }

    return $factory->create([
        'title' => $title,
        'slug' => $slug,
    ]);
}

function repair_service(): CatalogPartTypeRepairService
{
    return app(CatalogPartTypeRepairService::class);
}

function keep_product_in_current_category_after_grouped_update(Product $product): void
{
    $productId = (int) $product->getKey();
    $categoryId = (int) $product->product_category_id;
    $restored = false;

    DB::listen(function ($query) use ($productId, $categoryId, &$restored): void {
        if ($restored || preg_match('/^update\\s+[`"]?products[`"]?\\s+/i', trim($query->sql)) !== 1) {
            return;
        }

        $restored = true;

        DB::table('products')
            ->where('id', $productId)
            ->update(['product_category_id' => $categoryId]);
    });
}

test('dry run recognizes hierarchical and flat technical categories without mutating database', function () {
    $arka = legacy_category('Арка', 'legacy-arka');
    $hierarchical = legacy_category("  Внутренняя\n универсальная ", 'legacy-inner', $arka);
    $flat = legacy_category(" Арка  /  Внутренняя универсальная ", 'legacy-flat');
    Product::factory()->forCategory($hierarchical)->create();
    Product::factory()->forCategory($flat)->create();

    $before = [
        'categories' => ProductCategory::withTrashed()->count(),
        'part_types' => PartType::withTrashed()->count(),
        'products' => Product::query()->get()->map->getAttributes()->all(),
    ];

    $plan = repair_service()->inspect();

    expect(collect($plan->technicalCategories)->where('part_type_path', 'arka/vnutrenniaia-universalnaia'))->toHaveCount(2)
        ->and($plan->blockers)->toBe([])
        ->and(ProductCategory::withTrashed()->count())->toBe($before['categories'])
        ->and(PartType::withTrashed()->count())->toBe($before['part_types'])
        ->and(Product::query()->get()->map->getAttributes()->all())->toBe($before['products']);
});

test('hierarchical and flat legacy categories reuse the same part type', function () {
    $arka = legacy_category('Арка', 'legacy-arka');
    $hierarchical = legacy_category('Внутренняя универсальная', 'legacy-inner', $arka);
    $flat = legacy_category('Арка / Внутренняя универсальная', 'legacy-flat');
    $hierarchicalProduct = Product::factory()->forCategory($hierarchical)->generic()->create();
    $flatProduct = Product::factory()->forCategory($flat)->generic()->create();

    repair_service()->apply(repair_service()->inspect());

    expect($hierarchicalProduct->fresh()->part_type_id)->toBe($flatProduct->fresh()->part_type_id)
        ->and(PartType::withTrashed()->where('full_slug', 'arka/vnutrenniaia-universalnaia')->count())->toBe(1);
});

test('repair moves a lone legacy store category while preserving its id and product data', function () {
    $root = legacy_category('Кузовные детали', 'kuzovnye-detali');
    $legacy = legacy_category('Пороги', 'porogi', $root);
    $product = Product::factory()->forCategory($legacy)->generic()->create()->fresh();
    $legacyId = $legacy->id;
    $attributesBefore = $product->getAttributes();

    $plan = repair_service()->inspect();
    $result = repair_service()->apply($plan);

    $legacy->refresh();

    expect($result->counter('legacy_store_categories_moved'))->toBe(1)
        ->and($legacy->id)->toBe($legacyId)
        ->and($legacy->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova/porogi')
        ->and($legacy->depth)->toBe(2)
        ->and($legacy->is_active)->toBeTrue()
        ->and($product->fresh()->product_category_id)->toBe($legacy->id)
        ->and($product->fresh()->getAttributes())->toBe($attributesBefore)
        ->and(ProductCategory::withTrashed()->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova/porogi')->count())->toBe(1);
});

test('repair merges old and canonical store categories without deleting the old record', function () {
    $root = legacy_category('Кузовные детали', 'kuzovnye-detali');
    $repairRoot = legacy_category('Ремонтные элементы кузова', 'remontnye-elementy-kuzova', $root);
    $old = legacy_category('Пороги', 'porogi', $root);
    $canonical = legacy_category('Пороги', 'porogi', $repairRoot);
    $product = Product::factory()->forCategory($old)->create();
    $partType = PartType::factory()->forCategory($old)->create(['title' => 'Ручной тип']);

    $result = repair_service()->apply(repair_service()->inspect());
    $countsAfterFirst = [
        ProductCategory::withTrashed()->count(),
        PartType::withTrashed()->count(),
        Product::withTrashed()->count(),
    ];
    $secondPlan = repair_service()->inspect();
    $second = repair_service()->apply($secondPlan);

    expect($result->counter('legacy_store_categories_merged'))->toBe(1)
        ->and($result->counter('manual_products_updated'))->toBe(1)
        ->and($product->fresh()->product_category_id)->toBe($canonical->id)
        ->and($partType->fresh()->product_category_id)->toBe($canonical->id)
        ->and(ProductCategory::withTrashed()->find($old->id))->not->toBeNull()
        ->and(ProductCategory::withTrashed()->find($old->id)->is_active)->toBeFalse()
        ->and($canonical->fresh()->is_active)->toBeTrue()
        ->and(collect($secondPlan->legacyStoreCategories)->firstWhere('category_id', $old->id)['action'])->toBe('already_merged')
        ->and($second->counter('legacy_store_categories_merged'))->toBe(0)
        ->and($second->counter('manual_products_updated'))->toBe(0)
        ->and([ProductCategory::withTrashed()->count(), PartType::withTrashed()->count(), Product::withTrashed()->count()])->toBe($countsAfterFirst);
});

test('unexpected child under legacy store category is a blocker and apply changes nothing', function () {
    $root = legacy_category('Кузовные детали', 'kuzovnye-detali');
    $legacy = legacy_category('Пороги', 'porogi', $root);
    $child = legacy_category('Экспериментальная подкатегория', 'experiment', $legacy);
    $product = Product::factory()->forCategory($legacy)->create()->fresh();
    $snapshot = [
        'categories' => ProductCategory::withTrashed()->get()->map->getAttributes()->all(),
        'products' => Product::query()->get()->map->getAttributes()->all(),
        'part_types' => PartType::withTrashed()->count(),
    ];

    $plan = repair_service()->inspect();

    expect(collect($plan->blockers)->pluck('code'))->toContain('legacy_store_category_has_children')
        ->and(fn () => repair_service()->apply($plan))->toThrow(RuntimeException::class)
        ->and(ProductCategory::withTrashed()->get()->map->getAttributes()->all())->toBe($snapshot['categories'])
        ->and(Product::query()->get()->map->getAttributes()->all())->toBe($snapshot['products'])
        ->and(PartType::withTrashed()->count())->toBe($snapshot['part_types'])
        ->and($child->fresh())->not->toBeNull()
        ->and($product->fresh()->product_category_id)->toBe($legacy->id);
});

test('repair maps known technical categories to exact part types and store categories', function () {
    $porog = legacy_category('Порог', 'legacy-porog');
    $arka = legacy_category('Арка', 'legacy-arka');
    $arkaInner = legacy_category('Внутренняя универсальная', 'legacy-inner', $arka);
    $penka = legacy_category('Пенка', 'legacy-penka');
    $penkaRear = legacy_category('Задней двери', 'legacy-rear', $penka);
    $lonzheron = legacy_category('Лонжерон', 'legacy-lonzheron');
    $floorRepairKit = legacy_category('Ремкомплект пола', 'legacy-floor-repair-kit');
    $endCap = legacy_category('Торцевая заглушка', 'legacy-end-cap');

    $products = [
        'porog' => Product::factory()->forCategory($porog)->generic()->create(),
        'arka/vnutrenniaia-universalnaia' => Product::factory()->forCategory($arkaInner)->generic()->create(),
        'penka/zadnei-dveri' => Product::factory()->forCategory($penkaRear)->generic()->create(),
        'lonzheron' => Product::factory()->forCategory($lonzheron)->generic()->create(),
        'remkomplekt-pola' => Product::factory()->forCategory($floorRepairKit)->generic()->create(),
        'tortsevaia-zaglushka' => Product::factory()->forCategory($endCap)->generic()->create(),
    ];

    repair_service()->apply(repair_service()->inspect());

    $expectedCategories = [
        'porog' => 'kuzovnye-detali/remontnye-elementy-kuzova/porogi',
        'arka/vnutrenniaia-universalnaia' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'penka/zadnei-dveri' => 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki',
        'lonzheron' => 'kuzovnye-detali/remontnye-elementy-kuzova/lonzherony',
        'remkomplekt-pola' => 'kuzovnye-detali/remontnye-elementy-kuzova/remkomplekty-pola',
        'tortsevaia-zaglushka' => 'kuzovnye-detali/remontnye-elementy-kuzova/zaglushki',
    ];

    foreach ($products as $partTypePath => $product) {
        $product->refresh();

        expect($product->product_type)->toBe(ProductType::AutoPart)
            ->and($product->partType->full_slug)->toBe($partTypePath)
            ->and($product->category->full_slug)->toBe($expectedCategories[$partTypePath]);
    }

    foreach ([$porog, $arka, $arkaInner, $penka, $penkaRear, $lonzheron, $floorRepairKit, $endCap] as $legacyCategory) {
        $stored = ProductCategory::withTrashed()->findOrFail($legacyCategory->id);
        expect($stored->is_active)->toBeFalse()
            ->and($stored->deleted_at)->toBeNull();
    }
});

test('manual product keeps all business fields variants fitments and images', function () {
    $legacy = legacy_category('Порог', 'legacy-porog');
    $product = Product::factory()->forCategory($legacy)->generic()->create([
        'title' => 'Ручной порог',
        'slug' => 'manual-threshold',
        'sku' => 'MANUAL-001',
        'status' => ProductStatus::Archived,
        'short_description' => 'Краткое описание',
        'description' => 'Полное описание',
        'price' => '12500.50',
        'old_price' => '15000.00',
        'stock_status' => StockStatus::OutOfStock,
        'position' => 77,
        'is_featured' => true,
        'meta_title' => 'Ручной meta title',
        'meta_description' => 'Ручной meta description',
        'import_key' => null,
        'import_source' => null,
        'last_import_run_id' => null,
    ])->fresh();
    $variant = ProductVariant::factory()->forProduct($product)->create();
    $fitment = ProductFitment::factory()->forProduct($product)->create();
    $image = ProductImage::factory()->forProduct($product)->create(['source_type' => ProductImage::SOURCE_MANUAL]);

    $protectedFields = [
        'title', 'slug', 'sku', 'status', 'short_description', 'description', 'price', 'old_price',
        'stock_status', 'position', 'is_featured', 'meta_title', 'meta_description', 'import_key',
        'import_source', 'last_import_run_id', 'created_at',
    ];
    $before = Arr::only($product->getRawOriginal(), $protectedFields);
    $variantBefore = $variant->fresh()->getRawOriginal();
    $fitmentBefore = $fitment->fresh()->getRawOriginal();
    $imageBefore = $image->fresh()->getRawOriginal();

    $result = repair_service()->apply(repair_service()->inspect());
    $product->refresh();

    expect($result->counter('manual_products_updated'))->toBe(1)
        ->and(Arr::only($product->getRawOriginal(), $protectedFields))->toBe($before)
        ->and($product->product_type)->toBe(ProductType::AutoPart)
        ->and($product->part_type_id)->not->toBeNull()
        ->and($product->product_category_id)->not->toBe($legacy->id)
        ->and($variant->fresh()->getRawOriginal())->toBe($variantBefore)
        ->and($fitment->fresh()->getRawOriginal())->toBe($fitmentBefore)
        ->and($image->fresh()->getRawOriginal())->toBe($imageBefore);
});

test('imported product keeps import metadata and related records', function () {
    $legacy = legacy_category('Лонжерон', 'legacy-lonzheron');
    $product = Product::factory()->forCategory($legacy)->generic()->create([
        'title' => 'Импортный лонжерон',
        'import_key' => 'catalog:lonzheron:1',
        'import_source' => 'catalog',
        'last_import_run_id' => 'run-20260717',
    ]);
    $variant = ProductVariant::factory()->forProduct($product)->create();
    $fitment = ProductFitment::factory()->forProduct($product)->create();
    $image = ProductImage::factory()->forProduct($product)->create(['source_type' => ProductImage::SOURCE_IMPORT]);

    $result = repair_service()->apply(repair_service()->inspect());

    expect($result->counter('imported_products_updated'))->toBe(1)
        ->and($product->fresh()->title)->toBe('Импортный лонжерон')
        ->and($product->fresh()->import_key)->toBe('catalog:lonzheron:1')
        ->and($product->fresh()->import_source)->toBe('catalog')
        ->and($product->fresh()->last_import_run_id)->toBe('run-20260717')
        ->and($variant->fresh())->not->toBeNull()
        ->and($fitment->fresh())->not->toBeNull()
        ->and($image->fresh())->not->toBeNull();
});

test('unknown child under approved grouping root creates one fallback part type and is idempotent', function (
    string $rootTitle,
    string $rootSlug,
    string $childTitle,
    string $childSlug,
    string $expectedRootPartTypePath,
    string $expectedPartTypePath,
) {
    $root = legacy_category($rootTitle, $rootSlug);
    $unknown = legacy_category($childTitle, $childSlug, $root);
    $product = Product::factory()->forCategory($unknown)->generic()->create();

    $firstPlan = repair_service()->inspect();
    $first = repair_service()->apply($firstPlan);
    $countsAfterFirst = [
        ProductCategory::withTrashed()->count(),
        PartType::withTrashed()->count(),
        Product::withTrashed()->count(),
    ];
    $partTypeId = $product->fresh()->part_type_id;
    $second = repair_service()->apply(repair_service()->inspect());

    expect(collect($firstPlan->technicalCategories)->pluck('category_id'))->toContain($unknown->id)
        ->and(collect($firstPlan->unknownChildren)->pluck('category_id'))->toContain($unknown->id)
        ->and(collect($firstPlan->suspects)->pluck('category_id'))->not->toContain($unknown->id)
        ->and(collect($firstPlan->warnings)->pluck('code'))->toContain('unknown_child_fallback')
        ->and($first->counter('fallback_used'))->toBe(1)
        ->and($product->fresh()->partType->full_slug)->toBe($expectedPartTypePath)
        ->and(PartType::withTrashed()->where('full_slug', $expectedRootPartTypePath)->count())->toBe(1)
        ->and(PartType::withTrashed()->where('full_slug', $expectedPartTypePath)->count())->toBe(1)
        ->and($product->fresh()->partType->default_image_key)->toBeNull()
        ->and($product->fresh()->category->full_slug)->toBe('kuzovnye-detali/remontnye-elementy-kuzova')
        ->and($product->fresh()->part_type_id)->toBe($partTypeId)
        ->and($unknown->fresh()->is_active)->toBeFalse()
        ->and([ProductCategory::withTrashed()->count(), PartType::withTrashed()->count(), Product::withTrashed()->count()])->toBe($countsAfterFirst)
        ->and($second->counter('part_types_created'))->toBe(0)
        ->and($second->counter('imported_products_updated'))->toBe(0)
        ->and($second->counter('manual_products_updated'))->toBe(0);
})->with([
    'arch child' => [
        'Арка',
        'legacy-arka',
        'Передняя универсальная',
        'legacy-front-universal',
        'arka',
        'arka/peredniaia-universalnaia',
    ],
    'foam child' => [
        'Пенка',
        'legacy-penka',
        'Средней двери',
        'legacy-middle-door',
        'penka',
        'penka/srednei-dveri',
    ],
    'reinforcement child' => [
        'Усилитель',
        'legacy-usilitel',
        'Внутренний',
        'legacy-inner',
        'usilitel',
        'usilitel/vnutrennii',
    ],
]);

test('unknown child under non grouping technical root is reported as a suspect and keeps its root active', function (
    string $rootTitle,
    string $rootSlug,
    string $childTitle,
    string $childSlug,
    string $forbiddenPartTypePath,
) {
    $root = legacy_category($rootTitle, $rootSlug);
    $child = legacy_category($childTitle, $childSlug, $root);
    $product = Product::factory()->forCategory($child)->generic()->create()->fresh();
    $rootBefore = $root->fresh()->getRawOriginal();
    $categoryBefore = $child->fresh()->getRawOriginal();
    $productBefore = $product->getAttributes();

    $plan = repair_service()->inspect();
    $rootPlan = collect($plan->technicalCategories)->firstWhere('category_id', $root->id);

    expect(collect($plan->suspects)->pluck('category_id'))->toContain($child->id)
        ->and(collect($plan->technicalCategories)->pluck('category_id'))->not->toContain($child->id)
        ->and(collect($plan->unknownChildren)->pluck('category_id'))->not->toContain($child->id)
        ->and($rootPlan['can_deactivate'])->toBeFalse()
        ->and($rootPlan['suspect_descendant_ids'])->toContain($child->id)
        ->and(collect($plan->warnings)->pluck('code'))->toContain('legacy_category_kept_active');

    $result = repair_service()->apply($plan);

    expect($result->counter('fallback_used'))->toBe(0)
        ->and($result->counter('technical_categories_deactivated'))->toBe(0)
        ->and($result->counter('technical_categories_kept_active'))->toBe(1)
        ->and(PartType::withTrashed()->where('full_slug', $forbiddenPartTypePath)->exists())->toBeFalse()
        ->and($product->fresh()->getAttributes())->toBe($productBefore)
        ->and($product->fresh()->product_type)->toBe(ProductType::Generic)
        ->and($product->fresh()->part_type_id)->toBeNull()
        ->and($product->fresh()->product_category_id)->toBe($child->id)
        ->and($root->fresh()->getRawOriginal())->toBe($rootBefore)
        ->and($root->fresh()->is_active)->toBeTrue()
        ->and($root->fresh()->deleted_at)->toBeNull()
        ->and($child->fresh()->getRawOriginal())->toBe($categoryBefore)
        ->and($child->fresh()->is_active)->toBeTrue()
        ->and($child->fresh()->deleted_at)->toBeNull();
})->with([
    'threshold child' => [
        'Порог',
        'legacy-porog',
        'Декоративная накладка',
        'legacy-decorative-trim',
        'porog/dekorativnaia-nakladka',
    ],
    'longeron child' => [
        'Лонжерон',
        'legacy-lonzheron',
        'Аксессуар',
        'legacy-accessory',
        'lonzheron/aksessuar',
    ],
    'floor repair kit child' => [
        'Ремкомплект пола',
        'legacy-floor-repair-kit',
        'Инструмент',
        'legacy-tool',
        'remkomplekt-pola/instrument',
    ],
    'end cap child' => [
        'Торцевая заглушка',
        'legacy-end-cap',
        'Универсальная',
        'legacy-universal',
        'tortsevaia-zaglushka/universalnaia',
    ],
]);

test('previously deactivated legacy root is reactivated when an unresolved descendant remains', function () {
    $root = legacy_category('Порог', 'legacy-porog');
    $root->forceFill(['is_active' => false])->saveQuietly();
    $suspect = legacy_category('Декоративная накладка', 'legacy-decorative-trim', $root);
    $product = Product::factory()->forCategory($suspect)->generic()->create()->fresh();
    $productBefore = $product->getAttributes();

    $first = repair_service()->apply(repair_service()->inspect());
    $rootAfterFirst = $root->fresh()->getAttributes();
    $second = repair_service()->apply(repair_service()->inspect());

    expect($root->fresh()->is_active)->toBeTrue()
        ->and($suspect->fresh()->is_active)->toBeTrue()
        ->and($product->fresh()->getAttributes())->toBe($productBefore)
        ->and($first->counter('technical_categories_kept_active'))->toBe(1)
        ->and(collect($first->warnings)->where('code', 'legacy_category_kept_active'))->toHaveCount(1)
        ->and($root->fresh()->getAttributes())->toBe($rootAfterFirst)
        ->and($second->counter('technical_categories_kept_active'))->toBe(1)
        ->and($second->counter('technical_categories_deactivated'))->toBe(0)
        ->and($second->counter('fallback_used'))->toBe(0)
        ->and(collect($second->warnings)->where('code', 'legacy_category_kept_active'))->toHaveCount(1);
});

test('deep unresolved descendant keeps the whole legacy branch active without changing its product', function () {
    $root = legacy_category('Порог', 'legacy-porog');
    $intermediate = legacy_category('Декоративные элементы', 'legacy-decorative-elements', $root);
    $leaf = legacy_category('Накладка', 'legacy-trim', $intermediate);
    $product = Product::factory()->forCategory($leaf)->generic()->create()->fresh();
    $before = [
        'root' => $root->fresh()->getRawOriginal(),
        'intermediate' => $intermediate->fresh()->getRawOriginal(),
        'leaf' => $leaf->fresh()->getRawOriginal(),
        'product' => $product->getAttributes(),
    ];

    $plan = repair_service()->inspect();
    $rootPlan = collect($plan->technicalCategories)->firstWhere('category_id', $root->id);
    $result = repair_service()->apply($plan);

    expect(collect($plan->suspects)->pluck('category_id'))->toContain($intermediate->id, $leaf->id)
        ->and($rootPlan['can_deactivate'])->toBeFalse()
        ->and($rootPlan['unresolved_descendant_ids'])->toContain($intermediate->id, $leaf->id)
        ->and($rootPlan['unresolved_descendant_products'])->toBe(1)
        ->and($result->counter('technical_categories_kept_active'))->toBe(1)
        ->and($root->fresh()->getRawOriginal())->toBe($before['root'])
        ->and($intermediate->fresh()->getRawOriginal())->toBe($before['intermediate'])
        ->and($leaf->fresh()->getRawOriginal())->toBe($before['leaf'])
        ->and($leaf->fresh()->parent_id)->toBe($intermediate->id)
        ->and($intermediate->fresh()->parent_id)->toBe($root->id)
        ->and($product->fresh()->getAttributes())->toBe($before['product']);
});

test('mixed legacy branch migrates known child but keeps suspect child and root active idempotently', function () {
    $root = legacy_category('Арка', 'legacy-arka');
    $known = legacy_category('Задняя', 'legacy-rear', $root);
    $suspect = legacy_category('Декоративный молдинг', 'legacy-decorative-moulding', $root);
    $knownProduct = Product::factory()->forCategory($known)->generic()->create();
    $suspectProduct = Product::factory()->forCategory($suspect)->generic()->create()->fresh();
    $suspectProductBefore = $suspectProduct->getAttributes();

    $plan = repair_service()->inspect();
    $rootPlan = collect($plan->technicalCategories)->firstWhere('category_id', $root->id);
    $knownPlan = collect($plan->technicalCategories)->firstWhere('category_id', $known->id);
    $first = repair_service()->apply($plan);
    $countsAfterFirst = [
        ProductCategory::withTrashed()->count(),
        PartType::withTrashed()->count(),
        Product::withTrashed()->count(),
    ];
    $rootAfterFirst = $root->fresh()->getAttributes();
    $suspectAfterFirst = $suspect->fresh()->getAttributes();
    $second = repair_service()->apply(repair_service()->inspect());

    expect(collect($plan->suspects)->pluck('category_id'))->toContain($suspect->id)
        ->and(collect($plan->unknownChildren)->pluck('category_id'))->not->toContain($suspect->id)
        ->and($rootPlan['can_deactivate'])->toBeFalse()
        ->and($knownPlan['can_deactivate'])->toBeTrue()
        ->and(collect($plan->warnings)->pluck('code'))->toContain('legacy_category_kept_active')
        ->and($knownProduct->fresh()->partType->full_slug)->toBe('arka/zadniaia')
        ->and($known->fresh()->is_active)->toBeFalse()
        ->and($suspectProduct->fresh()->getAttributes())->toBe($suspectProductBefore)
        ->and($suspect->fresh()->is_active)->toBeTrue()
        ->and($root->fresh()->is_active)->toBeTrue()
        ->and($first->counter('technical_categories_deactivated'))->toBe(1)
        ->and($first->counter('technical_categories_kept_active'))->toBe(1)
        ->and($first->counter('fallback_used'))->toBe(0)
        ->and([
            ProductCategory::withTrashed()->count(),
            PartType::withTrashed()->count(),
            Product::withTrashed()->count(),
        ])->toBe($countsAfterFirst)
        ->and($root->fresh()->getAttributes())->toBe($rootAfterFirst)
        ->and($suspect->fresh()->getAttributes())->toBe($suspectAfterFirst)
        ->and($second->counter('part_types_created'))->toBe(0)
        ->and($second->counter('imported_products_updated'))->toBe(0)
        ->and($second->counter('manual_products_updated'))->toBe(0)
        ->and($second->counter('technical_categories_deactivated'))->toBe(0)
        ->and($second->counter('technical_categories_kept_active'))->toBe(1)
        ->and($second->counter('fallback_used'))->toBe(0);
});

test('fully recognized legacy branch is completely deactivated after all products are migrated', function () {
    $root = legacy_category('Арка', 'legacy-arka');
    $rear = legacy_category('Задняя', 'legacy-rear', $root);
    $inner = legacy_category('Внутренняя', 'legacy-inner', $root);
    $rearProduct = Product::factory()->forCategory($rear)->generic()->create();
    $innerProduct = Product::factory()->forCategory($inner)->generic()->create();

    $plan = repair_service()->inspect();
    $result = repair_service()->apply($plan);

    expect(collect($plan->suspects))->toBeEmpty()
        ->and(collect($plan->technicalCategories)->firstWhere('category_id', $root->id)['can_deactivate'])->toBeTrue()
        ->and($rearProduct->fresh()->partType->full_slug)->toBe('arka/zadniaia')
        ->and($innerProduct->fresh()->partType->full_slug)->toBe('arka/vnutrenniaia')
        ->and($root->fresh()->is_active)->toBeFalse()
        ->and($rear->fresh()->is_active)->toBeFalse()
        ->and($inner->fresh()->is_active)->toBeFalse()
        ->and($root->fresh()->deleted_at)->toBeNull()
        ->and($rear->fresh()->deleted_at)->toBeNull()
        ->and($inner->fresh()->deleted_at)->toBeNull()
        ->and($result->counter('technical_categories_deactivated'))->toBe(3)
        ->and($result->counter('technical_categories_kept_active'))->toBe(0);
});

test('legacy root stays active when a product remains directly attached after the grouped update', function () {
    $root = legacy_category('Порог', 'legacy-porog');
    $sourceProduct = Product::factory()->forCategory($root)->generic()->create();
    $retainedProduct = Product::factory()->forCategory($root)->generic()->create();

    keep_product_in_current_category_after_grouped_update($retainedProduct);

    $result = repair_service()->apply(repair_service()->inspect());

    expect($sourceProduct->fresh()->product_category_id)->not->toBe($root->id)
        ->and($retainedProduct->fresh())->not->toBeNull()
        ->and($retainedProduct->fresh()->product_category_id)->toBe($root->id)
        ->and($root->fresh()->is_active)->toBeTrue()
        ->and($result->counter('technical_categories_deactivated'))->toBe(0)
        ->and($result->counter('technical_categories_kept_active'))->toBe(1)
        ->and(collect($result->warnings)->pluck('code'))->toContain('legacy_category_kept_active_remaining_products');
});

test('legacy ancestor stays active when a recognized child still has a direct product', function () {
    $root = legacy_category('Арка', 'legacy-arka');
    $child = legacy_category('Задняя', 'legacy-rear', $root);
    $sourceProduct = Product::factory()->forCategory($child)->generic()->create();
    $retainedProduct = Product::factory()->forCategory($child)->generic()->create();

    keep_product_in_current_category_after_grouped_update($retainedProduct);

    $result = repair_service()->apply(repair_service()->inspect());

    expect($sourceProduct->fresh()->product_category_id)->not->toBe($child->id)
        ->and($retainedProduct->fresh())->not->toBeNull()
        ->and($retainedProduct->fresh()->product_category_id)->toBe($child->id)
        ->and($child->fresh()->is_active)->toBeTrue()
        ->and($root->fresh()->is_active)->toBeTrue()
        ->and($result->counter('technical_categories_deactivated'))->toBe(0)
        ->and($result->counter('technical_categories_kept_active'))->toBe(2)
        ->and(collect($result->warnings)->pluck('code'))->toContain(
            'legacy_category_kept_active_remaining_products',
            'legacy_category_kept_active_active_descendant',
        );
});

test('suspicious categories including an unknown technical root are reported and never changed', function () {
    $suspect = legacy_category('Арки декоративные аксессуары', 'decorative-arches');
    $unknownRoot = legacy_category('Новая кузовная штука', 'new-body-thing');
    $product = Product::factory()->forCategory($suspect)->generic()->create()->fresh();
    $categoryBefore = $suspect->fresh()->getRawOriginal();
    $unknownRootBefore = $unknownRoot->fresh()->getRawOriginal();
    $productBefore = $product->getAttributes();

    $plan = repair_service()->inspect();
    repair_service()->apply($plan);

    expect(collect($plan->suspects)->pluck('category_id'))->toContain($suspect->id, $unknownRoot->id)
        ->and($suspect->fresh()->getRawOriginal())->toBe($categoryBefore)
        ->and($unknownRoot->fresh()->getRawOriginal())->toBe($unknownRootBefore)
        ->and($product->fresh()->getAttributes())->toBe($productBefore)
        ->and(PartType::query()->where('full_title', 'Арки декоративные аксессуары')->exists())->toBeFalse();
});

test('soft deleted known part type is restored with the same id and manual metadata preserved', function () {
    repair_service()->apply(repair_service()->inspect());
    $partType = PartType::query()->where('full_slug', 'porog')->firstOrFail();
    $partType->forceFill([
        'default_image_key' => 'manual-image-key',
        'meta_title' => 'Ручной SEO',
        'meta_description' => 'Ручное описание',
    ])->saveQuietly();
    $id = $partType->id;
    $partType->delete();

    $result = repair_service()->apply(repair_service()->inspect());
    $restored = PartType::query()->findOrFail($id);

    expect($result->counter('part_types_restored'))->toBeGreaterThanOrEqual(1)
        ->and($restored->id)->toBe($id)
        ->and($restored->default_image_key)->toBe('manual-image-key')
        ->and($restored->meta_title)->toBe('Ручной SEO')
        ->and($restored->meta_description)->toBe('Ручное описание')
        ->and(PartType::withTrashed()->where('full_slug', 'porog')->count())->toBe(1);
});

test('soft deleted technical category with products is migrated without restoring the legacy category', function () {
    $legacy = legacy_category('Порог', 'legacy-porog');
    $product = Product::factory()->forCategory($legacy)->generic()->create();
    $legacy->delete();

    repair_service()->apply(repair_service()->inspect());
    $stored = ProductCategory::withTrashed()->findOrFail($legacy->id);

    expect($product->fresh()->product_category_id)->not->toBe($legacy->id)
        ->and($stored->trashed())->toBeTrue()
        ->and($stored->is_active)->toBeFalse();
});

test('soft deleted products keep their deleted state while receiving repaired structural fields', function () {
    $legacy = legacy_category('Порог', 'legacy-porog');
    $product = Product::factory()->forCategory($legacy)->generic()->create();
    $productId = $product->id;
    $product->delete();

    repair_service()->apply(repair_service()->inspect());
    $stored = Product::withTrashed()->findOrFail($productId);

    expect($stored->trashed())->toBeTrue()
        ->and($stored->product_type)->toBe(ProductType::AutoPart)
        ->and($stored->part_type_id)->not->toBeNull()
        ->and($stored->product_category_id)->not->toBe($legacy->id);
});

test('bulk repair uses a bounded number of product update statements', function () {
    $legacy = legacy_category('Порог', 'legacy-porog');
    Product::factory()->count(250)->forCategory($legacy)->generic()->create();
    $productUpdates = 0;

    DB::listen(function ($query) use (&$productUpdates): void {
        if (preg_match('/^update\s+["`]?products["`]?\s+/i', trim($query->sql)) === 1) {
            $productUpdates++;
        }
    });

    repair_service()->apply(repair_service()->inspect());

    expect(Product::query()->whereNotNull('part_type_id')->count())->toBe(250)
        ->and($productUpdates)->toBeLessThanOrEqual(2);
});
