<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->superAdmin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('product table preserves title sku category and part type search contracts', function (): void {
    $category = ProductCategory::factory()->create(['title' => 'Search Category Needle']);
    $partType = PartType::factory()->forCategory($category)->create([
        'title' => 'Search Part Type Needle',
        'full_title' => 'Search Part Type Needle',
    ]);
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create([
        'title' => 'Admin MiddleNeedle Product',
        'sku' => 'ADMIN-PRODUCT-123',
    ]);
    ProductVariant::factory()->forProduct($product)->create(['sku' => 'ADMIN-VARIANT-987']);
    $other = Product::factory()->create(['title' => 'Unrelated product', 'sku' => null]);
    ProductVariant::factory()->forProduct($other)->create(['sku' => null]);

    foreach (['MiddleNeedle', 'PRODUCT-12', 'VARIANT-98', 'Category Needle', 'Part Type Needle'] as $search) {
        Livewire::test(ListProducts::class)
            ->searchTable($search)
            ->assertCanSeeTableRecords([$product])
            ->assertCanNotSeeTableRecords([$other]);
    }
});

test('product table search keeps trashed filter and pagination interactions', function (): void {
    $products = Product::factory()->count(12)->create([
        'title' => 'Filter Pagination Needle',
        'sku' => null,
    ]);
    $trashed = Product::factory()->create([
        'title' => 'Filter Pagination Needle Trashed',
        'sku' => null,
    ]);
    $trashed->delete();

    Livewire::test(ListProducts::class)
        ->set('tableRecordsPerPage', 25)
        ->searchTable('Pagination Needle')
        ->assertCanSeeTableRecords($products)
        ->assertCanNotSeeTableRecords([$trashed]);

    Livewire::test(ListProducts::class)
        ->set('tableRecordsPerPage', 25)
        ->searchTable('Pagination Needle')
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([...$products, $trashed]);
});
