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
use App\Models\VehicleGeneration;
use App\Services\Catalog\ProductAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('product rejects new inactive relations and preserves historical inactive relations', function (): void {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->forCategory($category)->create();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create();
    $inactiveCategory = ProductCategory::factory()->inactive()->create();
    $inactivePartType = PartType::factory()->inactive()->create();

    expect(fn () => app(ProductAdminService::class)->save($product, ['product_category_id' => $inactiveCategory->getKey()]))
        ->toThrow(ValidationException::class, 'неактивную категорию');
    expect(fn () => app(ProductAdminService::class)->save($product->refresh(), ['part_type_id' => $inactivePartType->getKey()]))
        ->toThrow(ValidationException::class, 'неактивный тип детали');

    $partType->update(['is_active' => false]);
    app(ProductAdminService::class)->save($product->refresh(), ['title' => 'Без потери связи']);

    expect($product->refresh()->part_type_id)->toBe($partType->getKey());
});

test('generic product consistency and incompatible template are validated server side', function (): void {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->create();
    $generic = Product::factory()->generic()->forCategory($category)->create();

    expect(fn () => app(ProductAdminService::class)->save($generic, [
        'product_type' => ProductType::Generic,
        'part_type_id' => $partType->getKey(),
    ]))->toThrow(ValidationException::class, 'У обычного товара');

    $otherPartType = PartType::factory()->create();
    $template = ProductOptionTemplate::factory()->create([
        'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
        'part_type_id' => $otherPartType->getKey(),
    ]);
    $autoPart = Product::factory()->forPartType($partType)->create();

    expect(fn () => app(ProductAdminService::class)->save($autoPart, ['product_option_template_id' => $template->getKey()]))
        ->toThrow(ValidationException::class, 'несовместим');
});

test('failed product service update rolls back every changed field', function (): void {
    $product = Product::factory()->create(['title' => 'Исходное название']);
    $inactive = ProductCategory::factory()->inactive()->create();

    expect(fn () => app(ProductAdminService::class)->save($product, [
        'title' => 'Не должно сохраниться',
        'product_category_id' => $inactive->getKey(),
    ]))->toThrow(ValidationException::class);

    expect($product->refresh()->title)->toBe('Исходное название');
});

test('forged nullable relation identifiers are rejected before SQL without changing product', function (string $field, mixed $value): void {
    $product = Product::factory()->create([
        'product_category_id' => null,
        'part_type_id' => null,
        'title' => 'Исходный товар',
    ]);

    expect(fn () => app(ProductAdminService::class)->save($product, [
        'title' => 'Поддельное изменение',
        $field => $value,
    ]))->toThrow(ValidationException::class, 'положительным целым числом');

    expect($product->refresh()->title)->toBe('Исходный товар')
        ->and($product->{$field})->toBeNull();
})->with([
    'category zero' => ['product_category_id', 0],
    'part type zero' => ['part_type_id', '0'],
    'category negative' => ['product_category_id', -10],
    'part type arbitrary string' => ['part_type_id', 'не-id'],
]);

test('invalid product type becomes validation without changing the product', function (): void {
    $product = Product::factory()->create(['title' => 'Исходный товар']);

    expect(fn () => app(ProductAdminService::class)->save($product, [
        'title' => 'Не должно сохраниться',
        'product_type' => 'forged-product-type',
    ]))->toThrow(ValidationException::class, 'Выбран некорректный тип товара.');

    expect($product->refresh()->title)->toBe('Исходный товар')
        ->and($product->product_type)->toBe(ProductType::AutoPart);
});

test('product force delete is rejected while soft delete and restore preserve its graph and files', function (): void {
    Storage::fake('public');
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create();
    $imagePath = 'uploads/products/'.$product->getKey().'/force-delete-guard.webp';
    $image = ProductImage::factory()->forVariant($variant)->create(['path' => $imagePath]);
    $fitment = ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration(VehicleGeneration::factory()->create())
        ->create();
    Storage::disk('public')->put($imagePath, 'image');

    expect(fn () => $product->forceDelete())
        ->toThrow(ValidationException::class, 'Безвозвратное удаление товаров запрещено');

    expect($product->refresh()->trashed())->toBeFalse()
        ->and($variant->refresh()->exists)->toBeTrue()
        ->and($image->refresh()->exists)->toBeTrue()
        ->and($fitment->refresh()->exists)->toBeTrue()
        ->and(Storage::disk('public')->exists($imagePath))->toBeTrue();

    $product->delete();

    expect($product->refresh()->trashed())->toBeTrue()
        ->and($variant->refresh()->exists)->toBeTrue()
        ->and($image->refresh()->exists)->toBeTrue()
        ->and($fitment->refresh()->exists)->toBeTrue()
        ->and(Storage::disk('public')->exists($imagePath))->toBeTrue();

    $product->restore();

    expect($product->refresh()->trashed())->toBeFalse()
        ->and($product->variants()->count())->toBe(1)
        ->and($product->images()->count())->toBe(1)
        ->and($product->fitments()->count())->toBe(1);
});

test('product template id rejects forged input without changing the product', function (mixed $value): void {
    $product = Product::factory()->create(['title' => 'Исходный товар']);

    expect(fn () => app(ProductAdminService::class)->save($product, [
        'title' => 'Не должно сохраниться',
        'product_option_template_id' => $value,
    ]))->toThrow(ValidationException::class, 'положительным целым числом');

    expect($product->refresh()->title)->toBe('Исходный товар')
        ->and($product->product_option_template_id)->toBeNull();
})->with([
    'mixed string' => ['1abc'],
    'zero' => [0],
    'negative' => [-1],
    'array' => [['invalid']],
]);

test('product template id preserves nullable digit and inactive historical semantics', function (): void {
    $partType = PartType::factory()->create();
    $active = ProductOptionTemplate::factory()->create([
        'part_type_id' => $partType->getKey(),
        'is_active' => true,
    ]);
    $product = Product::factory()->forPartType($partType)->create();

    app(ProductAdminService::class)->save($product, [
        'product_option_template_id' => (string) $active->getKey(),
    ]);
    expect($product->refresh()->product_option_template_id)->toBe($active->getKey());

    $active->update(['is_active' => false]);
    app(ProductAdminService::class)->save($product->refresh(), ['title' => 'Исторический шаблон']);
    expect($product->refresh()->title)->toBe('Исторический шаблон');

    $otherInactive = ProductOptionTemplate::factory()->create([
        'part_type_id' => $partType->getKey(),
        'is_active' => false,
    ]);
    expect(fn () => app(ProductAdminService::class)->save($product->refresh(), [
        'product_option_template_id' => $otherInactive->getKey(),
    ]))->toThrow(ValidationException::class, 'неактивный шаблон');

    expect(fn () => app(ProductAdminService::class)->save($product->refresh(), [
        'product_option_template_id' => 999999,
    ]))->toThrow(ValidationException::class, 'не существует');

    app(ProductAdminService::class)->save($product->refresh(), ['product_option_template_id' => null]);
    expect($product->refresh()->product_option_template_id)->toBeNull();
});
