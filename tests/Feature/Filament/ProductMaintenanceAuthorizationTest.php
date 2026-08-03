<?php

use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\VehicleGenerations\Pages\EditVehicleGeneration;
use App\Filament\Resources\VehicleGenerations\Pages\ListVehicleGenerations;
use App\Filament\Resources\VehicleMakes\Pages\EditVehicleMake;
use App\Filament\Resources\VehicleMakes\Pages\ListVehicleMakes;
use App\Filament\Resources\VehicleModels\Pages\EditVehicleModel;
use App\Filament\Resources\VehicleModels\Pages\ListVehicleModels;
use App\Models\Order;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Media\DefaultProductImageService;
use App\Services\Media\ProductGalleryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('force delete is denied for every protected model and actor', function (): void {
    $records = [
        Product::factory()->create(),
        ProductCategory::factory()->create(),
        PartType::factory()->create(),
        VehicleMake::factory()->create(),
        VehicleModel::factory()->create(),
        VehicleGeneration::factory()->create(),
        User::factory()->create(),
        Order::factory()->create(),
    ];
    $invalidRole = User::factory()->create();
    DB::table('users')->whereKey($invalidRole->getKey())->update(['role' => 'invalid-role']);
    $invalidRole->refresh();

    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive_super_admin' => User::factory()->superAdmin()->inactive()->create(),
        'blocked_super_admin' => User::factory()->superAdmin()->blocked()->create(),
        'invalid_role' => $invalidRole,
    ];

    foreach ($actors as $actorLabel => $actor) {
        foreach ($records as $record) {
            $model = $record::class;

            expect($actor->can('forceDelete', $record), "{$actorLabel}:{$model}:forceDelete")->toBeFalse()
                ->and($actor->can('forceDeleteAny', $model), "{$actorLabel}:{$model}:forceDeleteAny")->toBeFalse();
        }
    }
});

test('super admin cannot see force delete record bulk or edit actions', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($superAdmin);

    $cases = [
        [ListProducts::class, EditProduct::class, Product::factory()->create()],
        [ListProductCategories::class, EditProductCategory::class, ProductCategory::factory()->create()],
        [ListVehicleMakes::class, EditVehicleMake::class, VehicleMake::factory()->create()],
        [ListVehicleModels::class, EditVehicleModel::class, VehicleModel::factory()->create()],
        [ListVehicleGenerations::class, EditVehicleGeneration::class, VehicleGeneration::factory()->create()],
    ];

    foreach ($cases as [$listPage, $editPage, $record]) {
        Livewire::test($listPage)
            ->assertTableActionHidden('forceDelete', $record)
            ->assertTableBulkActionHidden('forceDelete');

        Livewire::test($editPage, ['record' => $record->getKey()])
            ->assertActionHidden('forceDelete');
    }
});

test('force delete remains forbidden for the cascade fitment graph', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    $product = Product::factory()->create();

    ProductFitment::factory()
        ->forProduct($product)
        ->forVehicleGeneration($generation)
        ->create();

    expect($generation->fitments()->where('product_id', $product->getKey())->exists())->toBeTrue()
        ->and($superAdmin->can('forceDelete', $make))->toBeFalse()
        ->and($superAdmin->can('forceDelete', $model))->toBeFalse()
        ->and($superAdmin->can('forceDelete', $generation))->toBeFalse();
});

test('product maintenance action visibility follows the split permission matrix', function (): void {
    $product = Product::factory()->create();

    $superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($superAdmin);
    Livewire::test(ListProducts::class)
        ->assertTableActionVisible('table_make_default_main', $product)
        ->assertTableActionVisible('table_reset_gallery_to_default', $product)
        ->assertTableActionHidden('forceDelete', $product)
        ->assertTableBulkActionHidden('forceDelete');
    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionVisible('generate_variants_from_template')
        ->assertActionHidden('forceDelete');

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Livewire::test(ListProducts::class)
        ->assertTableActionVisible('table_make_default_main', $product)
        ->assertTableActionHidden('table_reset_gallery_to_default', $product)
        ->assertTableActionHidden('forceDelete', $product);
    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionVisible('generate_variants_from_template');

    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);
    Livewire::test(ListProducts::class)
        ->assertTableActionVisible('table_make_default_main', $product)
        ->assertTableActionHidden('table_reset_gallery_to_default', $product)
        ->assertTableActionHidden('forceDelete', $product);
    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionHidden('generate_variants_from_template');
});

test('forged gallery reset is rejected before rows or physical files change', function (string $actorFactoryState): void {
    Storage::fake('public');

    $actor = User::factory()->{$actorFactoryState}()->create();
    $product = Product::factory()->create();
    $manualPath = "products/{$product->getKey()}/manual.jpg";
    $importPath = "products/{$product->getKey()}/import.jpg";

    $manual = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => $manualPath,
        'source_type' => ProductImage::SOURCE_MANUAL,
    ]);
    $import = ProductImage::factory()->forProduct($product)->create([
        'path' => $importPath,
        'source_url' => 'https://example.test/import.jpg',
        'source_type' => ProductImage::SOURCE_IMPORT,
    ]);
    $default = ProductImage::factory()->forProduct($product)->create([
        'disk' => DefaultProductImageService::DISK,
        'path' => DefaultProductImageService::DIRECTORY.'/porog.webp',
        'source_type' => ProductImage::SOURCE_DEFAULT,
        'is_default' => true,
    ]);

    Storage::disk('public')->put($manualPath, 'manual-image');
    Storage::disk('public')->put($importPath, 'import-image');

    $gallery = Mockery::mock(ProductGalleryService::class);
    $gallery->shouldNotReceive('resetToDefault');
    app()->instance(ProductGalleryService::class, $gallery);

    $imageIds = [$manual->getKey(), $import->getKey(), $default->getKey()];
    sort($imageIds);
    $this->actingAs($actor);

    expect($actor->can('resetGallery', $product))->toBeFalse();

    Livewire::test(ListProducts::class)
        ->mountTableAction('table_reset_gallery_to_default', $product)
        ->assertActionNotMounted()
        ->assertOk();

    $persistedIds = ProductImage::query()
        ->where('product_id', $product->getKey())
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($persistedIds)->toBe($imageIds)
        ->and(Storage::disk('public')->exists($manualPath))->toBeTrue()
        ->and(Storage::disk('public')->exists($importPath))->toBeTrue();
})->with([
    'admin' => 'admin',
    'manager' => 'manager',
]);

test('forged variant generation is rejected before variants are created', function (): void {
    $manager = User::factory()->manager()->create();
    $product = Product::factory()->create();
    $this->actingAs($manager);

    expect($manager->can('generateVariants', $product))->toBeFalse();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->call('mountAction', 'generate_variants_from_template')
        ->assertActionNotMounted()
        ->assertOk();

    expect($product->variants()->count())->toBe(0);
});

test('non panel actors cannot reach either product maintenance action', function (string $actorState): void {
    $actor = match ($actorState) {
        'customer' => User::factory()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
    };
    $product = Product::factory()->create();

    expect($actor->can('generateVariants', $product))->toBeFalse()
        ->and($actor->can('resetGallery', $product))->toBeFalse();

    $this->actingAs($actor);
    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertForbidden();

    expect($product->variants()->count())->toBe(0)
        ->and($product->images()->count())->toBe(0);
})->with([
    'customer' => 'customer',
    'inactive super admin' => 'inactive',
    'blocked super admin' => 'blocked',
]);
