<?php

use App\Enums\ProductType;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\ProductOptionAdminService;
use App\Services\Catalog\ProductVariantAdminService;
use App\Services\Media\ProductGalleryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function a5_query_position(array $queries, string $table, ?string $operation = null): int
{
    $position = collect($queries)->search(function (string $sql) use ($table, $operation): bool {
        if (! str_contains($sql, $table)) {
            return false;
        }

        return $operation === null || str_starts_with($sql, $operation);
    });

    expect($position)->toBeInt();

    return $position;
}

test('direct product type transition locks product before fitment check and update', function (): void {
    $product = Product::factory()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $product->forceFill([
        'product_type' => ProductType::Generic,
        'part_type_id' => null,
        'product_option_template_id' => null,
    ])->save();

    $productLock = a5_query_position($queries, 'from "products"');
    $fitmentCheck = a5_query_position($queries, 'from "product_fitments"');
    $productUpdate = a5_query_position($queries, '"products"', 'update ');

    expect($productLock)->toBeLessThan($fitmentCheck)
        ->and($fitmentCheck)->toBeLessThan($productUpdate)
        ->and($product->refresh()->product_type)->toBe(ProductType::Generic);
});

test('product save locks assigned catalog relations before update', function (): void {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->forCategory($category)->create();
    $template = ProductOptionTemplate::factory()->create(['part_type_id' => $partType->getKey()]);
    $product = Product::factory()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $product->forceFill([
        'product_category_id' => $category->getKey(),
        'part_type_id' => $partType->getKey(),
        'product_option_template_id' => $template->getKey(),
    ])->save();

    $productLock = a5_query_position($queries, 'from "products"');
    $categoryLock = a5_query_position($queries, 'from "product_categories"');
    $partTypeLock = a5_query_position($queries, 'from "part_types"');
    $templateLock = a5_query_position($queries, 'from "product_option_templates"');
    $productUpdate = a5_query_position($queries, '"products"', 'update ');

    expect($productLock)->toBeLessThan($categoryLock)
        ->and($categoryLock)->toBeLessThan($partTypeLock)
        ->and($partTypeLock)->toBeLessThan($templateLock)
        ->and($templateLock)->toBeLessThan($productUpdate);
});

test('generation and fitment saves lock the complete vehicle chain in one order', function (): void {
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    $product = Product::factory()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();

    $modelLock = a5_query_position($queries, 'from "vehicle_models"');
    $makeLock = a5_query_position($queries, 'from "vehicle_makes"');
    $generationInsert = a5_query_position($queries, '"vehicle_generations"', 'insert ');

    expect($modelLock)->toBeLessThan($makeLock)
        ->and($makeLock)->toBeLessThan($generationInsert);

    $queries = [];
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();

    $productLock = a5_query_position($queries, 'from "products"');
    $generationLock = a5_query_position($queries, 'from "vehicle_generations"');
    $fitmentModelLock = a5_query_position($queries, 'from "vehicle_models"');
    $fitmentMakeLock = a5_query_position($queries, 'from "vehicle_makes"');
    $fitmentInsert = a5_query_position($queries, '"product_fitments"', 'insert ');

    expect($productLock)->toBeLessThan($generationLock)
        ->and($generationLock)->toBeLessThan($fitmentModelLock)
        ->and($fitmentModelLock)->toBeLessThan($fitmentMakeLock)
        ->and($fitmentMakeLock)->toBeLessThan($fitmentInsert);
});

test('variant delete locks product before the complete ordered variant set', function (): void {
    $product = Product::factory()->create();
    $target = ProductVariant::factory()->forProduct($product)->default()->create();
    $replacement = ProductVariant::factory()->forProduct($product)->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    app(ProductVariantAdminService::class)->delete($target, $replacement);

    $productLock = a5_query_position($queries, 'from "products"');
    $variantLocks = a5_query_position($queries, 'from "product_variants"');
    $variantDelete = a5_query_position($queries, '"product_variants"', 'delete ');

    expect($productLock)->toBeLessThan($variantLocks)
        ->and($variantLocks)->toBeLessThan($variantDelete)
        ->and($replacement->refresh()->is_default)->toBeTrue()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

test('every gallery mutation follows product variant images lock order', function (): void {
    $partType = PartType::factory()->create(['default_image_key' => 'porog']);
    $product = Product::factory()->forPartType($partType)->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create();
    $first = ProductImage::factory()->forVariant($variant)->main()->create();
    $second = ProductImage::factory()->forVariant($variant)->create();
    $gallery = app(ProductGalleryService::class);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $assertLockOrder = function () use (&$queries): void {
        $productLock = a5_query_position($queries, 'from "products"');
        $variantLock = a5_query_position($queries, 'from "product_variants"');
        $imagesLock = a5_query_position($queries, 'from "product_images"');

        expect($productLock)->toBeLessThan($variantLock)
            ->and($variantLock)->toBeLessThan($imagesLock);
    };

    $gallery->makeMain($second);
    $assertLockOrder();

    $queries = [];
    $second->forceFill(['alt' => 'Direct save order'])->save();
    $assertLockOrder();

    $queries = [];
    $gallery->setVisible($first, false);
    $assertLockOrder();

    $queries = [];
    $gallery->deleteImage($first);
    $assertLockOrder();

    $queries = [];
    $gallery->resetToDefault($product);
    $assertLockOrder();

    expect($product->images()->count())->toBe(1)
        ->and($product->images()->where('is_main', true)->count())->toBe(1);
});

test('template update locks linked products before template and rechecks products before update', function (): void {
    $admin = User::factory()->admin()->create();
    $template = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
    ]);
    Product::factory()->count(2)->create(['product_option_template_id' => $template->getKey()]);
    $template->update(['is_active' => false]);
    $template->refresh();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    app(ProductOptionAdminService::class)->updateTemplate(
        $admin,
        $template,
        [...$template->attributesToArray(), 'title' => 'Шаблон после безопасного обновления'],
        [],
    );

    $productQueries = collect($queries)
        ->keys()
        ->filter(fn (int $index): bool => str_contains($queries[$index], 'from "products"'))
        ->values();
    $templateLock = a5_query_position($queries, 'from "product_option_templates"');
    $templateUpdate = a5_query_position($queries, '"product_option_templates"', 'update ');
    $productsBeforeTemplate = $productQueries->filter(fn (int $index): bool => $index < $templateLock);
    $productsAfterTemplate = $productQueries->filter(fn (int $index): bool => $index > $templateLock);

    expect($productsBeforeTemplate->count())->toBeGreaterThanOrEqual(2)
        ->and($productsAfterTemplate->count())->toBeGreaterThanOrEqual(1)
        ->and($templateLock)->toBeLessThan($templateUpdate)
        ->and($template->refresh()->title)->toBe('Шаблон после безопасного обновления');
});
