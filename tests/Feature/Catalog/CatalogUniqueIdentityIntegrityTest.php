<?php

use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('product category full path conflicts are validation errors without partial changes', function (): void {
    $firstParent = ProductCategory::factory()->create(['title' => 'Первый', 'slug' => 'first']);
    $secondParent = ProductCategory::factory()->create(['title' => 'Второй', 'slug' => 'second']);
    $occupied = ProductCategory::factory()->forParent($secondParent)->create(['title' => 'Занято', 'slug' => 'shared']);
    $source = ProductCategory::factory()->forParent($firstParent)->create(['title' => 'Исходная', 'slug' => 'source']);

    expect(fn () => ProductCategory::factory()->forParent($secondParent)->create([
        'title' => 'Дубликат',
        'slug' => ' shared ',
    ]))->toThrow(ValidationException::class, 'Полный путь категории уже используется');

    expect(fn () => $source->update([
        'parent_id' => $secondParent->getKey(),
        'slug' => 'shared',
    ]))->toThrow(ValidationException::class, 'Полный путь категории уже используется');

    expect($source->refresh()->parent_id)->toBe($firstParent->getKey())
        ->and($source->slug)->toBe('source')
        ->and($source->full_slug)->toBe('first/source')
        ->and($occupied->refresh()->full_slug)->toBe('second/shared');
});

test('product category restore rebuilds a stale path before unique validation', function (): void {
    $firstParent = ProductCategory::factory()->create(['title' => 'Первый', 'slug' => 'first']);
    $secondParent = ProductCategory::factory()->create(['title' => 'Второй', 'slug' => 'second']);
    ProductCategory::factory()->forParent($secondParent)->create(['title' => 'Занято', 'slug' => 'shared']);
    $deleted = ProductCategory::factory()->forParent($firstParent)->create(['title' => 'Удалённая', 'slug' => 'shared']);
    $deleted->delete();
    ProductCategory::withTrashed()->whereKey($deleted)->update(['parent_id' => $secondParent->getKey()]);

    expect(fn () => $deleted->refresh()->restore())
        ->toThrow(ValidationException::class, 'Полный путь категории уже используется');

    expect($deleted->refresh()->trashed())->toBeTrue()
        ->and($deleted->full_slug)->toBe('first/shared');
});

test('part type full path conflicts are normalized on create update and restore', function (): void {
    $firstParent = PartType::factory()->create(['title' => 'Первый узел']);
    $secondParent = PartType::factory()->create(['title' => 'Второй узел']);
    $occupied = PartType::factory()->childOf($secondParent)->create(['title' => 'Общий тип']);
    $source = PartType::factory()->childOf($firstParent)->create(['title' => 'Исходный тип']);

    expect(fn () => PartType::factory()->childOf($secondParent)->create(['title' => ' Общий тип ']))
        ->toThrow(ValidationException::class, 'Полный путь типа детали уже используется');

    expect(fn () => $source->update([
        'parent_id' => $secondParent->getKey(),
        'title' => 'Общий тип',
    ]))->toThrow(ValidationException::class, 'Полный путь типа детали уже используется');

    expect($source->refresh()->parent_id)->toBe($firstParent->getKey())
        ->and($source->title)->toBe('Исходный тип');

    $deleted = PartType::factory()->childOf($firstParent)->create(['title' => 'Общий тип']);
    $deleted->delete();
    PartType::withTrashed()->whereKey($deleted)->update(['parent_id' => $secondParent->getKey()]);

    expect(fn () => $deleted->refresh()->restore())
        ->toThrow(ValidationException::class, 'Полный путь типа детали уже используется');

    expect($deleted->refresh()->trashed())->toBeTrue()
        ->and($occupied->refresh()->parent_id)->toBe($secondParent->getKey());
});

test('vehicle make model and generation identities are unique only in their database scopes', function (): void {
    $firstMake = VehicleMake::factory()->create(['title' => 'Make One', 'norm_key' => 'make-one']);

    expect(fn () => VehicleMake::factory()->create(['title' => 'Duplicate Make', 'norm_key' => ' make-one ']))
        ->toThrow(ValidationException::class, 'Марка с таким нормализованным ключом');

    $firstModel = VehicleModel::factory()->forMake($firstMake)->create([
        'title' => 'Model One',
        'slug' => 'model-one',
        'norm_key' => 'model-one',
    ]);

    expect(fn () => VehicleModel::factory()->forMake($firstMake)->create([
        'title' => 'Other title',
        'slug' => 'model-one',
        'norm_key' => 'other-key',
    ]))->toThrow(ValidationException::class, 'таким slug');
    expect(fn () => VehicleModel::factory()->forMake($firstMake)->create([
        'title' => 'Another title',
        'slug' => 'another-slug',
        'norm_key' => 'model-one',
    ]))->toThrow(ValidationException::class, 'нормализованным ключом');

    $secondMake = VehicleMake::factory()->create(['title' => 'Make Two', 'norm_key' => 'make-two']);
    $sameModelInOtherMake = VehicleModel::factory()->forMake($secondMake)->create([
        'title' => 'Model One',
        'slug' => 'model-one',
        'norm_key' => 'model-one',
    ]);

    $firstGeneration = VehicleGeneration::factory()->forVehicleModel($firstModel)->create([
        'title' => 'Generation One',
        'slug' => 'generation-one',
        'norm_key' => 'generation-one',
    ]);

    expect(fn () => VehicleGeneration::factory()->forVehicleModel($firstModel)->create([
        'title' => 'Other generation',
        'slug' => 'generation-one',
        'norm_key' => 'other-generation',
    ]))->toThrow(ValidationException::class, 'поколение с таким slug');
    expect(fn () => VehicleGeneration::factory()->forVehicleModel($firstModel)->create([
        'title' => 'Another generation',
        'slug' => 'another-generation',
        'norm_key' => 'generation-one',
    ]))->toThrow(ValidationException::class, 'нормализованным ключом');

    $sameGenerationInOtherModel = VehicleGeneration::factory()->forVehicleModel($sameModelInOtherMake)->create([
        'title' => 'Generation One',
        'slug' => 'generation-one',
        'norm_key' => 'generation-one',
    ]);

    expect($firstMake->refresh()->norm_key)->toBe('make-one')
        ->and($firstModel->refresh()->slug)->toBe('model-one')
        ->and($firstGeneration->refresh()->slug)->toBe('generation-one')
        ->and($sameModelInOtherMake->exists)->toBeTrue()
        ->and($sameGenerationInOtherModel->exists)->toBeTrue();
});
