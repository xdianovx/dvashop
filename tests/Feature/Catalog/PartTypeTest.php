<?php

use App\Exceptions\Catalog\PartTypeCycleException;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('root part type normalizes title and builds path fields', function () {
    $partType = PartType::factory()->create([
        'title' => "  Внутренняя\n   универсальная  ",
    ]);

    expect($partType->fresh())
        ->title->toBe('Внутренняя универсальная')
        ->slug->toBe('vnutrenniaia-universalnaia')
        ->full_slug->toBe('vnutrenniaia-universalnaia')
        ->full_title->toBe('Внутренняя универсальная')
        ->depth->toBe(0);
});

test('child part type builds local and full paths and ordered relationships', function () {
    $parent = PartType::factory()->create(['title' => 'Арка']);
    $second = PartType::factory()->childOf($parent)->create([
        'title' => 'Внутренняя универсальная',
        'position' => 20,
    ]);
    $first = PartType::factory()->childOf($parent)->create([
        'title' => 'Задняя',
        'position' => 10,
    ]);

    expect($second->fresh())
        ->slug->toBe('vnutrenniaia-universalnaia')
        ->full_slug->toBe('arka/vnutrenniaia-universalnaia')
        ->full_title->toBe('Арка / Внутренняя универсальная')
        ->depth->toBe(1)
        ->and($second->parent->is($parent))->toBeTrue()
        ->and($parent->children->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('changing a title updates the current path and every descendant', function () {
    $root = PartType::factory()->create(['title' => 'Арка']);
    $child = PartType::factory()->childOf($root)->create(['title' => 'Внутренняя']);
    $grandchild = PartType::factory()->childOf($child)->create(['title' => 'Левая']);

    $root->update(['title' => 'Арка кузова']);

    expect($root->fresh())
        ->full_slug->toBe('arka-kuzova')
        ->full_title->toBe('Арка кузова')
        ->and($child->fresh())
        ->full_slug->toBe('arka-kuzova/vnutrenniaia')
        ->full_title->toBe('Арка кузова / Внутренняя')
        ->depth->toBe(1)
        ->and($grandchild->fresh())
        ->full_slug->toBe('arka-kuzova/vnutrenniaia/levaia')
        ->full_title->toBe('Арка кузова / Внутренняя / Левая')
        ->depth->toBe(2);
});

test('changing a parent updates a tree deeper than two levels', function () {
    $oldRoot = PartType::factory()->create(['title' => 'Арка']);
    $newRoot = PartType::factory()->create(['title' => 'Кузов']);
    $child = PartType::factory()->childOf($oldRoot)->create(['title' => 'Внутренняя']);
    $grandchild = PartType::factory()->childOf($child)->create(['title' => 'Левая']);
    $greatGrandchild = PartType::factory()->childOf($grandchild)->create(['title' => 'Усиленная']);

    $child->update(['parent_id' => $newRoot->id]);

    expect($child->fresh())
        ->full_slug->toBe('kuzov/vnutrenniaia')
        ->depth->toBe(1)
        ->and($grandchild->fresh())
        ->full_slug->toBe('kuzov/vnutrenniaia/levaia')
        ->depth->toBe(2)
        ->and($greatGrandchild->fresh())
        ->full_slug->toBe('kuzov/vnutrenniaia/levaia/usilennaia')
        ->depth->toBe(3);
});

test('saving without title or parent changes does not rewrite descendants', function () {
    $root = PartType::factory()->create(['title' => 'Арка']);
    $child = PartType::factory()->childOf($root)->create(['title' => 'Задняя']);
    $sentinel = '2000-01-01 00:00:00';
    DB::table('part_types')->where('id', $child->id)->update(['updated_at' => $sentinel]);

    $root->save();

    expect($child->fresh()->updated_at->format('Y-m-d H:i:s'))->toBe($sentinel);
});

test('part type cannot be its own parent', function () {
    $partType = PartType::factory()->create(['title' => 'Арка']);

    $partType->update(['parent_id' => $partType->id]);
})->throws(PartTypeCycleException::class, 'создаст цикл');

test('part type cannot create a two node cycle', function () {
    $first = PartType::factory()->create(['title' => 'Арка']);
    $second = PartType::factory()->childOf($first)->create(['title' => 'Задняя']);

    $first->update(['parent_id' => $second->id]);
})->throws(PartTypeCycleException::class, 'создаст цикл');

test('part type cannot create a deep cycle', function () {
    $first = PartType::factory()->create(['title' => 'Арка']);
    $second = PartType::factory()->childOf($first)->create(['title' => 'Задняя']);
    $third = PartType::factory()->childOf($second)->create(['title' => 'Левая']);

    $first->update(['parent_id' => $third->id]);
})->throws(PartTypeCycleException::class, 'создаст цикл');

test('part type category and product relationships work in both directions', function () {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->forCategory($category)->create(['title' => 'Порог']);
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create();

    expect($partType->productCategory->is($category))->toBeTrue()
        ->and($partType->products->first()->is($product))->toBeTrue()
        ->and($product->partType->is($partType))->toBeTrue()
        ->and($category->partTypes->first()->is($partType))->toBeTrue();
});

test('soft deleting a part type keeps its product assigned and intact', function () {
    $partType = PartType::factory()->create(['title' => 'Порог']);
    $product = Product::factory()->forPartType($partType)->create();

    $partType->delete();

    expect(Product::query()->find($product->id))->not->toBeNull()
        ->and($product->fresh()->part_type_id)->toBe($partType->id);
});

test('force deleting a part type nulls the product foreign key', function () {
    $partType = PartType::factory()->create(['title' => 'Порог']);
    $product = Product::factory()->forPartType($partType)->create();

    $partType->forceDelete();

    expect($product->fresh()->part_type_id)->toBeNull();
});

test('full slug is unique', function () {
    PartType::factory()->create(['title' => 'Порог']);
    PartType::factory()->create(['title' => 'Порог']);
})->throws(QueryException::class);

test('local slug can repeat below different parents', function () {
    $firstParent = PartType::factory()->create(['title' => 'Арка']);
    $secondParent = PartType::factory()->create(['title' => 'Пенка']);
    $firstChild = PartType::factory()->childOf($firstParent)->create(['title' => 'Задняя']);
    $secondChild = PartType::factory()->childOf($secondParent)->create(['title' => 'Задняя']);

    expect($firstChild->slug)->toBe($secondChild->slug)
        ->and($firstChild->full_slug)->not->toBe($secondChild->full_slug);
});

test('part type factory supports root child activity and category states', function () {
    $category = ProductCategory::factory()->create();
    $root = PartType::factory()->root()->inactive()->forCategory($category)->create(['title' => 'Арка']);
    $child = PartType::factory()->childOf($root)->active()->create(['title' => 'Задняя']);

    expect($root->parent_id)->toBeNull()
        ->and($root->is_active)->toBeFalse()
        ->and($root->product_category_id)->toBe($category->id)
        ->and($child->parent_id)->toBe($root->id)
        ->and($child->is_active)->toBeTrue();
});
