<?php

use App\Services\Catalog\CatalogRelationIdNormalizer;
use Illuminate\Validation\ValidationException;

test('catalog relation id normalizer accepts only exact positive integer representations', function (): void {
    $normalizer = app(CatalogRelationIdNormalizer::class);

    expect($normalizer->nullablePositive(null, 'relation_id'))->toBeNull()
        ->and($normalizer->nullablePositive('', 'relation_id'))->toBeNull()
        ->and($normalizer->nullablePositive(1, 'relation_id'))->toBe(1)
        ->and($normalizer->nullablePositive('1', 'relation_id'))->toBe(1)
        ->and($normalizer->nullablePositive('0007', 'relation_id'))->toBe(7)
        ->and($normalizer->positive('42', 'relation_id'))->toBe(42);
});

test('catalog relation id normalizer translates every forged value to russian validation', function (): void {
    $normalizer = app(CatalogRelationIdNormalizer::class);
    $forged = [null, '', 0, '0', -1, '-1', '1abc', '1.5', 1.5, [], new stdClass, true, false, ' 1', '1 '];

    foreach ($forged as $value) {
        expect(fn () => $normalizer->positive($value, 'relation_id'))
            ->toThrow(ValidationException::class, 'положительным целым числом');
    }

    foreach (array_slice($forged, 2) as $value) {
        expect(fn () => $normalizer->nullablePositive($value, 'relation_id'))
            ->toThrow(ValidationException::class, 'положительным целым числом');
    }
});
