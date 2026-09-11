<?php

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;

function searchVehicleFixture(string $makeTitle = 'Toyota', string $modelTitle = 'Camry', array $makeAliases = ['тойота'], array $modelAliases = ['камри']): array
{
    $make = VehicleMake::factory()->create(['title' => $makeTitle, 'search_aliases' => $makeAliases]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => $modelTitle, 'search_aliases' => $modelAliases]);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'XV70', 'body' => 'Седан', 'years_label' => '2017–2023']);
    $product = Product::factory()->create(['title' => 'Ремонтный комплект '.$makeTitle.' '.$modelTitle, 'sku' => 'PART-'.$make->id]);
    $variant = ProductVariant::factory()->forProduct($product)->default()->create(['sku' => 'VARIANT-'.$make->id, 'title' => 'NeverIndexThisVariantName']);
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();

    return compact('make', 'model', 'generation', 'product', 'variant');
}

function searchHiddenVariantFixture(Product $product, string $sku, bool $hideGroup): ProductVariant
{
    $group = ProductOptionGroup::factory()->create();
    $value = ProductOptionValue::factory()->forGroup($group)->create();
    $variant = ProductVariant::factory()->forProduct($product)->create(['sku' => $sku, 'is_active' => true, 'title' => 'Hidden variant '.$sku]);
    ProductVariantOptionValue::create([
        'product_variant_id' => $variant->id, 'product_option_group_id' => $group->id, 'product_option_value_id' => $value->id,
    ]);
    ($hideGroup ? $group : $value)->update(['is_active' => false]);

    return $variant;
}
