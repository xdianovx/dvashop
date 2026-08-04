<?php

use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\VehicleMakes\Pages\ListVehicleMakes;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VehicleMake;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('catalog structural resources expose no mutation bulk or force delete actions', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $cases = [
        [ListProductCategories::class, ProductCategory::factory()->create()],
        [ListVehicleMakes::class, VehicleMake::factory()->create()],
    ];

    foreach ($cases as [$page, $record]) {
        Livewire::test($page)
            ->assertTableActionHidden('forceDelete', $record)
            ->assertTableBulkActionHidden('delete')
            ->assertTableBulkActionHidden('restore')
            ->assertTableBulkActionHidden('forceDelete');
    }

    Livewire::test(ListProducts::class)
        ->assertTableActionHidden('forceDelete', Product::factory()->create())
        ->assertTableBulkActionVisible('delete')
        ->assertTableBulkActionHidden('forceDelete');
});

test('manager remains view only for structural catalog resources', function (): void {
    $manager = User::factory()->manager()->create();
    $category = ProductCategory::factory()->create();
    $this->actingAs($manager);

    Livewire::test(ListProductCategories::class)
        ->assertCanSeeTableRecords([$category])
        ->assertTableActionHidden('edit', $category)
        ->assertTableActionHidden('toggle_active', $category)
        ->assertTableActionHidden('delete', $category)
        ->assertTableActionHidden('restore', $category);
});

test('confirmed category deactivation preserves every existing relation', function (): void {
    $admin = User::factory()->admin()->create();
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->forCategory($category)->create();
    $this->actingAs($admin);

    Livewire::test(ListProductCategories::class)
        ->callTableAction('toggle_active', $category)
        ->assertHasNoTableActionErrors();

    expect($category->refresh()->is_active)->toBeFalse()
        ->and($product->refresh()->product_category_id)->toBe($category->getKey());
});
