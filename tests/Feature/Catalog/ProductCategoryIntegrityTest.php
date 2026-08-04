<?php

use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Catalog\CatalogStructureAdminService;
use App\Services\Catalog\ProductAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('category rejects self parent and an arbitrarily deep descendant cycle', function (): void {
    $root = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->forParent($root)->create();
    $grandchild = ProductCategory::factory()->forParent($child)->create();

    expect(fn () => app(CatalogStructureAdminService::class)->saveCategory($root, ['parent_id' => $root->getKey()]))
        ->toThrow(ValidationException::class, 'Категория не может быть родителем самой себе.');

    expect(fn () => app(CatalogStructureAdminService::class)->saveCategory($root->refresh(), ['parent_id' => $grandchild->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя переместить категорию внутрь её потомка.');

    expect($root->refresh()->parent_id)->toBeNull()
        ->and(ProductCategoryResource::parentOptions($root))->not->toHaveKeys([$root->getKey(), $child->getKey(), $grandchild->getKey()]);
});

test('category delete and restore reject unsafe structural states without partial changes', function (): void {
    $parent = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->forParent($parent)->create();

    expect(fn () => app(CatalogStructureAdminService::class)->deleteCategory($parent))
        ->toThrow(ValidationException::class, 'дочерние категории');
    expect($parent->refresh()->trashed())->toBeFalse();

    $child->deleteQuietly();
    Product::factory()->forCategory($parent)->create();

    expect(fn () => app(CatalogStructureAdminService::class)->deleteCategory($parent->refresh()))
        ->toThrow(ValidationException::class, 'привязаны товары');
});

test('inactive category cannot be newly assigned but remains valid historically', function (): void {
    $active = ProductCategory::factory()->create();
    $inactive = ProductCategory::factory()->inactive()->create();
    $product = Product::factory()->forCategory($active)->create();

    expect(fn () => app(ProductAdminService::class)->save($product, ['product_category_id' => $inactive->getKey()]))
        ->toThrow(ValidationException::class, 'неактивную категорию');

    $active->update(['is_active' => false]);
    app(ProductAdminService::class)->save($product->refresh(), ['title' => 'Исторический товар']);

    expect($product->refresh()->product_category_id)->toBe($active->getKey())
        ->and($product->title)->toBe('Исторический товар');
});

test('category create and update reject forged parent ids without partial changes', function (mixed $value): void {
    $category = ProductCategory::factory()->create(['title' => 'Исходная категория']);
    $newCategory = ProductCategory::factory()->make(['parent_id' => $value]);

    expect(fn () => $newCategory->save())
        ->toThrow(ValidationException::class, 'положительным целым числом');
    expect($newCategory->exists)->toBeFalse();

    expect(fn () => app(CatalogStructureAdminService::class)->saveCategory($category, [
        'title' => 'Не должно сохраниться',
        'parent_id' => $value,
    ]))->toThrow(ValidationException::class, 'положительным целым числом');

    expect($category->refresh()->title)->toBe('Исходная категория')
        ->and($category->parent_id)->toBeNull();
})->with([
    'mixed string' => ['1abc'],
    'zero' => [0],
    'negative' => [-1],
    'array' => [['invalid']],
]);

test('category accepts a digit string parent id on create and update', function (): void {
    $parent = ProductCategory::factory()->create();
    $category = ProductCategory::factory()->make(['parent_id' => (string) $parent->getKey()]);
    $category->save();

    $otherParent = ProductCategory::factory()->create();
    app(CatalogStructureAdminService::class)->saveCategory($category, [
        'parent_id' => (string) $otherParent->getKey(),
    ]);

    expect($category->refresh()->parent_id)->toBe($otherParent->getKey());
});
