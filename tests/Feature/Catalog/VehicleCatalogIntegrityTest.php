<?php

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogStructureAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('used vehicle model and generation cannot be moved across their hierarchy', function (): void {
    $firstMake = VehicleMake::factory()->create();
    $secondMake = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($firstMake)->create();
    $otherModel = VehicleModel::factory()->forMake($secondMake)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    ProductFitment::factory()->forVehicleGeneration($generation)->forProduct(Product::factory()->create())->create();

    expect(fn () => app(CatalogStructureAdminService::class)->saveVehicleModel($model, ['vehicle_make_id' => $secondMake->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя перенести модель с поколениями');
    expect(fn () => app(CatalogStructureAdminService::class)->saveVehicleGeneration($generation, ['vehicle_model_id' => $otherModel->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя перенести поколение');

    expect($model->refresh()->vehicle_make_id)->toBe($firstMake->getKey())
        ->and($generation->refresh()->vehicle_model_id)->toBe($model->getKey());
});

test('inactive hierarchy rejects a new fitment while an existing fitment remains saveable', function (): void {
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    $product = Product::factory()->create();
    $historical = ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
    $generation->update(['is_active' => false]);

    $historical->update(['note' => 'Сохранено исторически']);

    expect($historical->refresh()->note)->toBe('Сохранено исторически');
    expect(fn () => ProductFitment::factory()->forProduct(Product::factory()->create())->forVehicleGeneration($generation)->create())
        ->toThrow(ValidationException::class, 'неактивную или удалённую');
});

test('administrative delete cannot cascade through a used vehicle tree', function (): void {
    $make = VehicleMake::factory()->create();
    VehicleModel::factory()->forMake($make)->create();

    expect(fn () => app(CatalogStructureAdminService::class)->deleteVehicle($make))
        ->toThrow(ValidationException::class, 'используемый элемент');
    expect($make->refresh()->trashed())->toBeFalse();
});

test('direct vehicle model paths enforce hierarchy invariants transactionally', function (): void {
    $initialTransactionLevel = DB::transactionLevel();
    $firstMake = VehicleMake::factory()->create();
    $secondMake = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($firstMake)->create();
    $otherModel = VehicleModel::factory()->forMake($secondMake)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    ProductFitment::factory()->forVehicleGeneration($generation)->forProduct(Product::factory()->create())->create();

    expect(fn () => $model->update(['vehicle_make_id' => $secondMake->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя перенести модель с поколениями');
    expect(fn () => $generation->update(['vehicle_model_id' => $otherModel->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя перенести поколение');

    expect($model->refresh()->vehicle_make_id)->toBe($firstMake->getKey())
        ->and($generation->refresh()->vehicle_model_id)->toBe($model->getKey())
        ->and(DB::transactionLevel())->toBe($initialTransactionLevel);
});

test('structural updates lock the changed record before checking child relations', function (): void {
    $firstMake = VehicleMake::factory()->create();
    $secondMake = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($firstMake)->create();
    $otherModel = VehicleModel::factory()->forMake($secondMake)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();
    ProductFitment::factory()->forVehicleGeneration($generation)->forProduct(Product::factory()->create())->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    expect(fn () => $model->update(['vehicle_make_id' => $secondMake->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя перенести модель с поколениями');

    $modelLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "vehicle_models"')
        && str_contains($sql, '"vehicle_models"."id" = ?'));
    $generationCheck = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "vehicle_generations"'));

    expect($modelLock)->toBeInt()
        ->and($generationCheck)->toBeInt()
        ->and($modelLock)->toBeLessThan($generationCheck);

    $queries = [];

    expect(fn () => $generation->update(['vehicle_model_id' => $otherModel->getKey()]))
        ->toThrow(ValidationException::class, 'Нельзя перенести поколение');

    $generationLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "vehicle_generations"')
        && str_contains($sql, '"vehicle_generations"."id" = ?'));
    $fitmentCheck = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "product_fitments"'));

    expect($generationLock)->toBeInt()
        ->and($fitmentCheck)->toBeInt()
        ->and($generationLock)->toBeLessThan($fitmentCheck)
        ->and($model->refresh()->vehicle_make_id)->toBe($firstMake->getKey())
        ->and($generation->refresh()->vehicle_model_id)->toBe($model->getKey());
});

test('vehicle model create and update reject forged make ids without partial changes', function (mixed $value): void {
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Исходная модель']);
    $newModel = VehicleModel::factory()->make(['vehicle_make_id' => $value]);

    expect(fn () => $newModel->save())
        ->toThrow(ValidationException::class, 'положительным целым числом');
    expect($newModel->exists)->toBeFalse();

    expect(fn () => app(CatalogStructureAdminService::class)->saveVehicleModel($model, [
        'title' => 'Не должно сохраниться',
        'vehicle_make_id' => $value,
    ]))->toThrow(ValidationException::class, 'положительным целым числом');

    expect($model->refresh()->title)->toBe('Исходная модель')
        ->and($model->vehicle_make_id)->toBe($make->getKey());
})->with([
    'mixed string' => ['1abc'],
    'zero' => [0],
    'negative' => [-1],
    'array' => [['invalid']],
]);

test('vehicle model validates missing deleted inactive and digit string make ids', function (): void {
    $active = VehicleMake::factory()->create();
    $digitModel = VehicleModel::factory()->make(['vehicle_make_id' => (string) $active->getKey()]);
    $digitModel->save();

    $missing = VehicleModel::factory()->make(['vehicle_make_id' => 999999]);
    expect(fn () => $missing->save())->toThrow(ValidationException::class, 'не существует или удалена');

    $deleted = VehicleMake::factory()->create();
    $deleted->deleteQuietly();
    expect(fn () => VehicleModel::factory()->make(['vehicle_make_id' => $deleted->getKey()])->save())
        ->toThrow(ValidationException::class, 'не существует или удалена');

    $inactive = VehicleMake::factory()->inactive()->create();
    expect(fn () => VehicleModel::factory()->make(['vehicle_make_id' => $inactive->getKey()])->save())
        ->toThrow(ValidationException::class, 'неактивную марку');

    expect($digitModel->refresh()->vehicle_make_id)->toBe($active->getKey());
});

test('vehicle generation create and update reject forged model ids without partial changes', function (mixed $value): void {
    $model = VehicleModel::factory()->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'Исходное поколение']);
    $newGeneration = VehicleGeneration::factory()->make(['vehicle_model_id' => $value]);

    expect(fn () => $newGeneration->save())
        ->toThrow(ValidationException::class, 'положительным целым числом');
    expect($newGeneration->exists)->toBeFalse();

    expect(fn () => app(CatalogStructureAdminService::class)->saveVehicleGeneration($generation, [
        'title' => 'Не должно сохраниться',
        'vehicle_model_id' => $value,
    ]))->toThrow(ValidationException::class, 'положительным целым числом');

    expect($generation->refresh()->title)->toBe('Исходное поколение')
        ->and($generation->vehicle_model_id)->toBe($model->getKey());
})->with([
    'mixed string' => ['1abc'],
    'zero' => [0],
    'negative' => [-1],
    'array' => [['invalid']],
]);

test('vehicle generation validates the complete model and make chain', function (): void {
    $activeModel = VehicleModel::factory()->create();
    $digitGeneration = VehicleGeneration::factory()->make([
        'vehicle_model_id' => (string) $activeModel->getKey(),
    ]);
    $digitGeneration->save();

    expect(fn () => VehicleGeneration::factory()->make(['vehicle_model_id' => 999999])->save())
        ->toThrow(ValidationException::class, 'не существует либо удалена');

    $deletedModel = VehicleModel::factory()->create();
    $deletedModel->deleteQuietly();
    expect(fn () => VehicleGeneration::factory()->make(['vehicle_model_id' => $deletedModel->getKey()])->save())
        ->toThrow(ValidationException::class, 'не существует либо удалена');

    $inactiveModel = VehicleModel::factory()->inactive()->create();
    expect(fn () => VehicleGeneration::factory()->make(['vehicle_model_id' => $inactiveModel->getKey()])->save())
        ->toThrow(ValidationException::class, 'неактивную модель или марку');

    $deletedMake = VehicleMake::factory()->create();
    $modelOfDeletedMake = VehicleModel::factory()->forMake($deletedMake)->create();
    $deletedMake->deleteQuietly();
    expect(fn () => VehicleGeneration::factory()->make(['vehicle_model_id' => $modelOfDeletedMake->getKey()])->save())
        ->toThrow(ValidationException::class, 'не существует либо удалена');

    $inactiveMake = VehicleMake::factory()->inactive()->create();
    $modelOfInactiveMake = VehicleModel::factory()->forMake($inactiveMake)->make();
    $modelOfInactiveMake->forceFill(['is_active' => true])->saveQuietly();
    expect(fn () => VehicleGeneration::factory()->make(['vehicle_model_id' => $modelOfInactiveMake->getKey()])->save())
        ->toThrow(ValidationException::class, 'неактивную модель или марку');

    expect($digitGeneration->refresh()->vehicle_model_id)->toBe($activeModel->getKey());
});
