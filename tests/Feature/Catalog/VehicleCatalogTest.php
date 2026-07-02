<?php

use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('vehicle make norm key is unique', function () {
    VehicleMake::factory()->create(['title' => 'Lada', 'norm_key' => 'lada']);

    VehicleMake::factory()->create(['title' => 'Lada duplicate', 'norm_key' => 'lada']);
})->throws(QueryException::class);

test('vehicle model slug and norm key are unique inside make', function () {
    $make = VehicleMake::factory()->create();

    VehicleModel::factory()->forMake($make)->create([
        'title' => 'Vesta',
        'slug' => 'vesta',
        'norm_key' => 'vesta',
    ]);

    VehicleModel::factory()->forMake($make)->create([
        'title' => 'Vesta duplicate',
        'slug' => 'vesta',
        'norm_key' => 'vesta-2',
    ]);
})->throws(QueryException::class);

test('vehicle generation slug and norm key are unique inside model', function () {
    $model = VehicleModel::factory()->create();

    VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'I',
        'slug' => 'i',
        'norm_key' => 'i',
    ]);

    VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'I duplicate',
        'slug' => 'i',
        'norm_key' => 'i-2',
    ]);
})->throws(QueryException::class);

test('vehicle relationships make model generation are linked', function () {
    $make = VehicleMake::factory()->create(['title' => 'Lada']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Vesta']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'I']);

    expect($make->models()->first()->is($model))->toBeTrue()
        ->and($model->make->is($make))->toBeTrue()
        ->and($model->generations()->first()->is($generation))->toBeTrue()
        ->and($generation->model->is($model))->toBeTrue();
});
