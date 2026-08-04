<?php

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Services\Catalog\ProductVariantAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('variant validates sku price and stock with Russian validation errors', function (): void {
    $product = Product::factory()->create();
    ProductVariant::factory()->forProduct($product)->create(['sku' => 'UNIQUE-A5']);

    expect(fn () => ProductVariant::factory()->forProduct($product)->create(['sku' => ' UNIQUE-A5 ']))
        ->toThrow(ValidationException::class, 'Такой SKU уже используется');
    expect(fn () => ProductVariant::factory()->forProduct($product)->create(['price' => -1]))
        ->toThrow(ValidationException::class, 'не могут быть отрицательными');
    expect(fn () => ProductVariant::factory()->forProduct($product)->create(['stock_quantity' => -1]))
        ->toThrow(ValidationException::class, 'не могут быть отрицательными');
});

test('default variant switch is atomic and rejects a variant from another product', function (): void {
    $product = Product::factory()->create();
    $first = ProductVariant::factory()->forProduct($product)->default()->create();
    $second = ProductVariant::factory()->forProduct($product)->create();
    $foreign = ProductVariant::factory()->create();

    app(ProductVariantAdminService::class)->setDefault($product, $second);

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);

    expect(fn () => app(ProductVariantAdminService::class)->setDefault($product, $foreign))
        ->toThrow(ValidationException::class, 'вариант другого товара');
    expect($product->variants()->where('is_default', true)->value('id'))->toBe($second->getKey());
});

test('last or current default variant cannot be deleted without a valid replacement', function (): void {
    $product = Product::factory()->create();
    $default = ProductVariant::factory()->forProduct($product)->default()->create();

    expect(fn () => app(ProductVariantAdminService::class)->delete($default))
        ->toThrow(ValidationException::class, 'последний вариант');

    $replacement = ProductVariant::factory()->forProduct($product)->create();
    app(ProductVariantAdminService::class)->delete($default->refresh(), $replacement);

    expect($default->fresh())->toBeNull()
        ->and($replacement->refresh()->is_default)->toBeTrue();
});

test('direct demotion and forged defaults always leave exactly one default variant', function (): void {
    $product = Product::factory()->create();
    $default = ProductVariant::factory()->forProduct($product)->default()->create();
    $second = ProductVariant::factory()->forProduct($product)->create();

    $default->update(['is_default' => false]);

    expect($default->refresh()->is_default)->toBeTrue()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);

    $default->update(['is_default' => true]);
    $second->update(['is_default' => true]);

    expect($default->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

test('failed default switch rolls back to the previous default', function (): void {
    $product = Product::factory()->create();
    $default = ProductVariant::factory()->forProduct($product)->default()->create();
    $replacement = ProductVariant::factory()->forProduct($product)->create();

    expect(fn () => DB::transaction(function () use ($product, $replacement): void {
        app(ProductVariantAdminService::class)->setDefault($product, $replacement);

        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class, 'rollback');

    expect($default->refresh()->is_default)->toBeTrue()
        ->and($replacement->refresh()->is_default)->toBeFalse()
        ->and($product->variants()->where('is_default', true)->count())->toBe(1);
});

test('existing variant cannot move between products and keeps every relation', function (): void {
    $product = Product::factory()->create();
    $otherProduct = Product::factory()->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create();
    $image = ProductImage::factory()->forVariant($variant)->create();
    $group = ProductOptionGroup::factory()->create();
    $value = ProductOptionValue::factory()->for($group, 'group')->create();
    $selection = ProductVariantOptionValue::factory()
        ->for($variant, 'variant')
        ->for($group, 'group')
        ->for($value, 'value')
        ->create();

    expect(fn () => $variant->update(['product_id' => $otherProduct->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя переносить существующий вариант');

    expect($variant->refresh()->product_id)->toBe($product->getKey())
        ->and($image->refresh()->product_id)->toBe($product->getKey())
        ->and($image->product_variant_id)->toBe($variant->getKey())
        ->and($selection->refresh()->product_variant_id)->toBe($variant->getKey())
        ->and($variant->images()->count())->toBe(1)
        ->and($variant->variantOptionValues()->count())->toBe(1);
});
