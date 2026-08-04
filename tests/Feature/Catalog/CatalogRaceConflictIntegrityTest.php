<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductAdminService;
use App\Services\Catalog\ProductVariantAdminService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('product sku duplicate race is translated without changing the product', function (): void {
    $product = Product::factory()->create(['sku' => 'PRODUCT-RACE-ORIGINAL']);
    $service = app(ProductAdminService::class);
    $known = new QueryException(
        'sqlite',
        'update products',
        [],
        new PDOException('UNIQUE constraint failed: products.sku', 23000),
    );

    expect(fn () => $service->guardProductSkuSave(fn (): bool => throw $known))
        ->toThrow(ValidationException::class, 'Такой SKU уже используется другим товаром');

    expect($product->refresh()->sku)->toBe('PRODUCT-RACE-ORIGINAL');
});

test('variant sku duplicate race is translated without changing the variant', function (): void {
    $variant = ProductVariant::factory()->create(['sku' => 'VARIANT-RACE-ORIGINAL']);
    $service = app(ProductVariantAdminService::class);
    $known = new QueryException(
        'sqlite',
        'update product_variants',
        [],
        new PDOException('UNIQUE constraint failed: product_variants.sku', 23000),
    );

    expect(fn () => $service->guardVariantSkuSave(fn (): bool => throw $known))
        ->toThrow(ValidationException::class, 'Такой SKU уже используется другим вариантом');

    expect($variant->refresh()->sku)->toBe('VARIANT-RACE-ORIGINAL');
});

test('sku guards do not hide unrelated database exceptions', function (): void {
    $unknown = new QueryException(
        'sqlite',
        'update products',
        [],
        new PDOException('FOREIGN KEY constraint failed', 23000),
    );

    expect(fn () => app(ProductAdminService::class)->guardProductSkuSave(fn (): bool => throw $unknown))
        ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');
    expect(fn () => app(ProductVariantAdminService::class)->guardVariantSkuSave(fn (): bool => throw $unknown))
        ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');
});

test('direct product model save translates a known sku race and rolls back changes', function (): void {
    $stored = Product::factory()->create([
        'sku' => 'DIRECT-PRODUCT-ORIGINAL',
        'title' => 'Исходный товар',
    ]);
    $prototype = new class extends Product
    {
        protected function performUpdate(Builder $query): bool
        {
            throw new QueryException(
                'sqlite',
                'update products',
                [],
                new PDOException('UNIQUE constraint failed: products.sku', 23000),
            );
        }
    };
    /** @var Product $product */
    $product = $prototype->newFromBuilder($stored->getAttributes());
    $product->forceFill([
        'sku' => 'DIRECT-PRODUCT-RACE',
        'title' => 'Не должно сохраниться',
    ]);

    expect(fn () => $product->save())
        ->toThrow(ValidationException::class, 'Такой SKU уже используется другим товаром');

    expect($stored->refresh()->sku)->toBe('DIRECT-PRODUCT-ORIGINAL')
        ->and($stored->title)->toBe('Исходный товар');
});

test('filament repeater relationship save uses the variant model sku race guard', function (): void {
    $product = Product::factory()->create();
    $variant = new class extends ProductVariant
    {
        protected function performInsert(Builder $query): bool
        {
            throw new QueryException(
                'sqlite',
                'insert into product_variants',
                [],
                new PDOException('UNIQUE constraint failed: product_variants.sku', 23000),
            );
        }
    };
    $variant->forceFill([
        'sku' => 'RELATIONSHIP-VARIANT-RACE',
        'price' => 1000,
        'stock_quantity' => 1,
        'is_default' => true,
        'is_active' => true,
    ]);

    expect(fn () => $product->variants()->save($variant))
        ->toThrow(ValidationException::class, 'Такой SKU уже используется другим вариантом');

    expect($product->variants()->count())->toBe(0)
        ->and(ProductVariant::query()->where('sku', 'RELATIONSHIP-VARIANT-RACE')->exists())->toBeFalse();
});

test('ordinary duplicate product sku returns validation through the model path', function (): void {
    Product::factory()->create(['sku' => 'DUPLICATE-PRODUCT-SKU']);

    expect(fn () => Product::factory()->create(['sku' => 'DUPLICATE-PRODUCT-SKU']))
        ->toThrow(ValidationException::class, 'Такой SKU уже используется другим товаром');

    expect(Product::query()->where('sku', 'DUPLICATE-PRODUCT-SKU')->count())->toBe(1);
});

test('direct product model save does not hide an unrelated database exception', function (): void {
    $stored = Product::factory()->create(['sku' => 'UNKNOWN-ERROR-ORIGINAL']);
    $prototype = new class extends Product
    {
        protected function performUpdate(Builder $query): bool
        {
            throw new QueryException(
                'sqlite',
                'update products',
                [],
                new PDOException('FOREIGN KEY constraint failed', 23000),
            );
        }
    };
    /** @var Product $product */
    $product = $prototype->newFromBuilder($stored->getAttributes());
    $product->forceFill(['sku' => 'UNKNOWN-ERROR-CHANGED']);

    expect(fn () => $product->save())
        ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');

    expect($stored->refresh()->sku)->toBe('UNKNOWN-ERROR-ORIGINAL');
});
