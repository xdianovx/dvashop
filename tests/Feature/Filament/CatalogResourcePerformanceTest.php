<?php

use App\Filament\Resources\PartTypes\PartTypeResource;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\VehicleGenerations\VehicleGenerationResource;
use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Filament\Resources\VehicleModels\VehicleModelResource;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('catalog index queries preload every relation and aggregate used by row state', function (): void {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->forCategory($category)->create();
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create();
    ProductImage::factory()->forProduct($product)->main()->create();

    $collections = [
        ProductCategoryResource::getEloquentQuery()->get(),
        PartTypeResource::getEloquentQuery()->get(),
        VehicleMakeResource::getEloquentQuery()->get(),
        VehicleModelResource::getEloquentQuery()->get(),
        VehicleGenerationResource::getEloquentQuery()->get(),
        ProductResource::getEloquentQuery()->get(),
    ];

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach ($collections[0] as $record) {
        $record->parent?->title;
        $record->children_count;
        $record->products_count;
    }
    foreach ($collections[1] as $record) {
        $record->productCategory?->title;
        $record->products_count;
    }
    foreach ($collections[2] as $record) {
        $record->models_count;
    }
    foreach ($collections[3] as $record) {
        $record->make?->title;
        $record->generations_count;
    }
    foreach ($collections[4] as $record) {
        $record->model?->make?->title;
        $record->fitments_count;
    }
    foreach ($collections[5] as $record) {
        $record->category?->title;
        $record->partType?->title;
        $record->defaultVariant?->price;
        $record->mainImage?->path;
        $record->images_count;
        $record->images->pluck('source_type');
    }

    expect(DB::getQueryLog())->toHaveCount(0);
});
