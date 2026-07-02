<?php

use App\Models\ProductCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product category full slug is generated from parent path', function () {
    $parent = ProductCategory::factory()->create([
        'title' => 'Кузовные детали',
        'slug' => 'body-parts',
    ]);

    $child = ProductCategory::factory()->forParent($parent)->create([
        'title' => 'Пороги',
        'slug' => 'thresholds',
    ]);

    expect($parent->fresh())
        ->full_slug->toBe('body-parts')
        ->depth->toBe(0)
        ->and($child->fresh())
        ->full_slug->toBe('body-parts/thresholds')
        ->depth->toBe(1);
});

test('product category full slug is recalculated when parent slug changes', function () {
    $parent = ProductCategory::factory()->create(['slug' => 'old-parent']);
    $child = ProductCategory::factory()->forParent($parent)->create(['slug' => 'child']);

    $parent->update(['slug' => 'new-parent']);

    expect($child->fresh()->full_slug)->toBe('new-parent/child');
});

test('product category full slug is unique', function () {
    ProductCategory::factory()->create(['title' => 'Пороги', 'slug' => 'porogi']);

    ProductCategory::factory()->create(['title' => 'Пороги дубль', 'slug' => 'porogi']);
})->throws(QueryException::class);
