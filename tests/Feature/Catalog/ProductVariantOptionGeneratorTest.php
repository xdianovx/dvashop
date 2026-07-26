<?php

use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductVariantOptionGenerator;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ProductOptionSeeder::class);
});

test('ProductVariantOption generator calculates template combinations and defaults', function () {
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $product = Product::factory()->create(['product_option_template_id' => $template->getKey()]);
    $generator = app(ProductVariantOptionGenerator::class);

    expect($generator->combinationsForTemplate($template))->toHaveCount(24)
        ->and($generator->defaultOptionValues($product))->toHaveCount(4);
});

test('ProductVariantOption generator creates missing variants idempotently and inherits base offer', function () {
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $product = Product::factory()->create(['product_option_template_id' => $template->getKey()]);
    ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => 'BASE-SKU',
        'price' => 12500,
        'old_price' => 13500,
        'stock_quantity' => 7,
        'stock_status' => StockStatus::PreOrder,
        'is_active' => true,
    ]);
    $generator = app(ProductVariantOptionGenerator::class);

    $created = $generator->createMissingVariants($product);
    $secondRun = $generator->createMissingVariants($product);

    expect($created)->toBe(23)
        ->and($secondRun)->toBe(0)
        ->and($product->variants()->count())->toBe(24)
        ->and($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and($product->variants()->whereNull('sku')->count())->toBe(23)
        ->and($product->variants()->where('price', 12500)->count())->toBe(24)
        ->and($product->variants()->where('stock_status', StockStatus::PreOrder->value)->count())->toBe(24)
        ->and($product->variants()->whereHas('optionValues')->count())->toBe(24)
        ->and($product->variants()->get()->every(fn (ProductVariant $variant): bool => is_array($variant->options) && count($variant->options) === 4))->toBeTrue();
});

test('ProductVariantOption generator respects the requested and absolute limits', function () {
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $product = Product::factory()->withDefaultVariant()->create([
        'product_option_template_id' => $template->getKey(),
    ]);
    $generator = app(ProductVariantOptionGenerator::class);

    expect($generator->createMissingVariants($product, 3))->toBe(3)
        ->and($product->variants()->count())->toBe(4)
        ->and($product->variants()->where('is_default', true)->count())->toBe(1)
        ->and(ProductVariantOptionGenerator::MAX_COMBINATIONS)->toBe(100);
});

test('ProductVariantOption generator recognizes a stable legacy snapshot and does not duplicate its combination', function () {
    $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->firstOrFail();
    $product = Product::factory()->create(['product_option_template_id' => $template->getKey()]);
    ProductVariant::factory()->forProduct($product)->default()->create([
        'options' => [
            'profile' => ['group' => 'Профиль', 'value' => 'Полный'],
            'position' => ['group' => 'Положение', 'value' => 'Левый + Правый'],
            'material' => ['group' => 'Материал', 'value' => 'Оцинковка'],
            'thickness' => ['group' => 'Толщина металла', 'value' => '1 мм'],
        ],
    ]);
    $generator = app(ProductVariantOptionGenerator::class);

    expect($generator->createMissingVariants($product))->toBe(23)
        ->and($generator->createMissingVariants($product))->toBe(0)
        ->and($product->variants()->count())->toBe(24);
});

test('ProductVariantOption combinations are capped before a large cartesian product is materialized', function () {
    $template = ProductOptionTemplate::factory()->create();

    foreach (range(1, 3) as $groupPosition) {
        $group = ProductOptionGroup::factory()->create(['position' => $groupPosition]);

        foreach (range(1, 5) as $valuePosition) {
            $value = ProductOptionValue::factory()->forGroup($group)->create(['position' => $valuePosition]);
            ProductOptionTemplateItem::query()->create([
                'product_option_template_id' => $template->getKey(),
                'product_option_group_id' => $group->getKey(),
                'product_option_value_id' => $value->getKey(),
                'position' => $valuePosition,
            ]);
        }
    }

    expect(app(ProductVariantOptionGenerator::class)->combinationsForTemplate($template))->toHaveCount(100);
});
