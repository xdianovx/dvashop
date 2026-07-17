<?php

use App\Filament\Resources\PartTypes\PartTypeResource;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('part type resource is registered and uses russian labels', function () {
    $resources = Filament::getPanel('admin')->getResources();

    expect($resources)->toContain(PartTypeResource::class)
        ->and(PartTypeResource::getNavigationGroup())->toBe('Каталог')
        ->and(PartTypeResource::getNavigationLabel())->toBe('Типы деталей')
        ->and(PartTypeResource::getModelLabel())->toBe('тип детали')
        ->and(PartTypeResource::getPluralModelLabel())->toBe('Типы деталей')
        ->and(ProductCategoryResource::getModelLabel())->toBe('категория магазина')
        ->and(ProductCategoryResource::getPluralModelLabel())->toBe('Категории магазина');
});

test('super admin can open part type list create and edit pages', function () {
    $user = User::factory()->superAdmin()->create();
    $partType = PartType::factory()->create(['title' => 'Порог']);

    $this->actingAs($user)->get('/admin/part-types')->assertOk();
    $this->actingAs($user)->get('/admin/part-types/create')->assertOk();
    $this->actingAs($user)->get('/admin/part-types/'.$partType->id.'/edit')->assertOk();
});

test('parent options exclude current type descendants and soft deleted types', function () {
    $root = PartType::factory()->create(['title' => 'Арка']);
    $child = PartType::factory()->childOf($root)->create(['title' => 'Задняя']);
    $grandchild = PartType::factory()->childOf($child)->create(['title' => 'Внутренняя']);
    $child->delete();
    $other = PartType::factory()->create(['title' => 'Порог']);
    $trashed = PartType::factory()->create(['title' => 'Удалённый тип']);
    $trashed->delete();

    $options = PartTypeResource::parentOptions($root);

    expect($options)->not->toHaveKey($root->id)
        ->not->toHaveKey($child->id)
        ->not->toHaveKey($grandchild->id)
        ->not->toHaveKey($trashed->id)
        ->toHaveKey($other->id);
});

test('store category options expose full paths', function () {
    $root = ProductCategory::factory()->create(['title' => 'Кузовные детали', 'slug' => 'kuzovnye-detali']);
    $child = ProductCategory::factory()->forParent($root)->create(['title' => 'Пороги', 'slug' => 'porogi']);

    $options = PartTypeResource::productCategoryOptions();

    expect($options[$child->id])->toContain('kuzovnye-detali/porogi');
});

test('part type resource query eager loads category and includes products count', function () {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->forCategory($category)->create(['title' => 'Порог']);
    Product::factory()->count(3)->forCategory($category)->forPartType($partType)->create();

    $record = PartTypeResource::getEloquentQuery()->findOrFail($partType->id);

    expect($record->relationLoaded('productCategory'))->toBeTrue()
        ->and($record->products_count)->toBe(3);
});

test('part type resource has no force delete actions and readonly path fields are declared', function () {
    $resourceSource = file_get_contents(app_path('Filament/Resources/PartTypes/PartTypeResource.php'));
    $editSource = file_get_contents(app_path('Filament/Resources/PartTypes/Pages/EditPartType.php'));

    expect($resourceSource)->toContain("Select::make('parent_id')")
        ->toContain("TextInput::make('title')")
        ->toContain("TextInput::make('full_slug')")
        ->toContain("TextInput::make('full_title')")
        ->toContain("TextInput::make('depth')")
        ->toContain("Select::make('product_category_id')")
        ->toContain("TextInput::make('default_image_key')")
        ->toContain("TextInput::make('position')")
        ->toContain("Toggle::make('is_active')")
        ->toContain("TextInput::make('meta_title')")
        ->toContain("Textarea::make('meta_description')")
        ->toContain('->dehydrated(false)')
        ->toContain("->withCount('products')")
        ->not->toContain('ForceDeleteAction')
        ->not->toContain('ForceDeleteBulkAction')
        ->and($editSource)->not->toContain('ForceDeleteAction');
});
