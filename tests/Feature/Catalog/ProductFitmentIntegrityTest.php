<?php

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\VehicleGeneration;
use App\Services\Catalog\ProductAdminService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('direct duplicate fitment create returns validation and preserves the original row', function (): void {
    $product = Product::factory()->create();
    $generation = VehicleGeneration::factory()->create();
    $original = ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create([
        'note' => 'Исходная',
    ]);

    expect(fn () => ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create())
        ->toThrow(ValidationException::class, 'Такая применяемость уже добавлена товару');

    expect($product->fitments()->count())->toBe(1)
        ->and($original->refresh()->note)->toBe('Исходная');
});

test('changing an existing fitment cannot create a duplicate pair', function (): void {
    $product = Product::factory()->create();
    $firstGeneration = VehicleGeneration::factory()->create();
    $secondGeneration = VehicleGeneration::factory()->create();
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($firstGeneration)->create();
    $changed = ProductFitment::factory()->forProduct($product)->forVehicleGeneration($secondGeneration)->create();

    expect(fn () => $changed->update(['vehicle_generation_id' => $firstGeneration->getKey()]))
        ->toThrow(ValidationException::class, 'Такая применяемость уже добавлена товару');

    expect($changed->refresh()->vehicle_generation_id)->toBe($secondGeneration->getKey())
        ->and($product->fitments()->count())->toBe(2);
});

test('fitment sync rejects duplicate input without changing existing rows', function (): void {
    $product = Product::factory()->create();
    $generation = VehicleGeneration::factory()->create();
    $original = ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create([
        'note' => 'До синхронизации',
    ]);

    expect(fn () => app(ProductAdminService::class)->syncFitments($product, [
        ['vehicle_generation_id' => $generation->getKey(), 'note' => 'Первый'],
        ['vehicle_generation_id' => $generation->getKey(), 'note' => 'Дубликат'],
    ]))->toThrow(ValidationException::class, 'Такая применяемость уже добавлена товару');

    expect($product->fitments()->count())->toBe(1)
        ->and($original->refresh()->note)->toBe('До синхронизации');
});

test('fitment unique race is translated while unrelated database errors propagate', function (): void {
    $product = Product::factory()->create();
    $generation = VehicleGeneration::factory()->create();
    $fitment = new ProductFitment([
        'product_id' => $product->getKey(),
        'vehicle_generation_id' => $generation->getKey(),
    ]);
    $service = app(ProductAdminService::class);
    $service->validateFitment($fitment);

    $known = new QueryException(
        'sqlite',
        'insert into product_fitments',
        [],
        new PDOException('UNIQUE constraint failed: product_fitments.product_id, product_fitments.vehicle_generation_id', 23000),
    );

    expect(fn () => $service->saveFitment($fitment, fn (): bool => throw $known))
        ->toThrow(ValidationException::class, 'Такая применяемость уже добавлена товару');

    $unknown = new QueryException(
        'sqlite',
        'insert into product_fitments',
        [],
        new PDOException('FOREIGN KEY constraint failed', 23000),
    );

    expect(fn () => $service->saveFitment($fitment, fn (): bool => throw $unknown))
        ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');
    expect($product->fitments()->count())->toBe(0);
});

test('fitment sync rejects malformed generation ids without partial changes', function (mixed $value): void {
    $product = Product::factory()->create();
    $generation = VehicleGeneration::factory()->create();
    $original = ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create([
        'note' => 'Исходная применяемость',
    ]);

    expect(fn () => app(ProductAdminService::class)->syncFitments($product, [[
        'vehicle_generation_id' => $value,
        'note' => 'Не должно сохраниться',
    ]]))->toThrow(ValidationException::class, 'положительным целым числом');

    expect($product->fitments()->count())->toBe(1)
        ->and($original->refresh()->note)->toBe('Исходная применяемость');
})->with([
    'mixed string' => ['1abc'],
    'zero' => [0],
    'negative' => [-1],
    'fraction' => [1.5],
    'array' => [['invalid']],
    'empty' => [''],
]);

test('direct fitment save rejects malformed relation ids before SQL', function (string $field, mixed $value): void {
    $product = Product::factory()->create();
    $generation = VehicleGeneration::factory()->create();
    $fitment = new ProductFitment([
        'product_id' => $product->getKey(),
        'vehicle_generation_id' => $generation->getKey(),
    ]);
    $fitment->setAttribute($field, $value);

    expect(fn () => $fitment->save())
        ->toThrow(ValidationException::class, 'положительным целым числом');

    expect($product->fitments()->count())->toBe(0);
})->with([
    'product mixed string' => ['product_id', '1abc'],
    'generation zero' => ['vehicle_generation_id', 0],
    'generation fraction' => ['vehicle_generation_id', 1.5],
    'generation array' => ['vehicle_generation_id', ['invalid']],
]);
