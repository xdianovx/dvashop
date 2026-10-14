<?php

use App\Services\Import\CatalogImportCell;

test('only numeric and textual one mean product presence', function (mixed $value, bool $present): void {
    $parsed = CatalogImportCell::parse($value);
    expect($parsed !== null)->toBe($present);
    if ($present) {
        expect($parsed['price'])->toBeNull()->and($parsed['image_url'])->toBeNull();
    }
})->with([
    [1, true], [1.0, true], ['1', true], ['1.0', true], ['1,00', true], [' 1 ', true],
    [0, false], ['0', false], [null, false], ['', false], ['   ', false],
    ['maver', false], ['abc', false], ['да', false], ['yes', false], ['true', false],
    [2, false], [-1, false], [1.5, false], ['2000', false], ['1.01', false],
    ['https://example.test/image.jpg', false], [true, false], [false, false],
]);
