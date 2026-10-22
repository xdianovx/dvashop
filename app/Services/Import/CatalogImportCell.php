<?php

namespace App\Services\Import;

use App\Support\CatalogText;

final class CatalogImportCell
{
    /** Only one or boolean/textual true means present. */
    public static function parse(mixed $value): ?array
    {
        if ($value === true) {
            $value = 'true';
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $normalized = mb_strtolower(CatalogText::plain($value, 1000));

        return $normalized === 'true' || preg_match('/^1(?:\.0+)?$/D', $normalized) === 1
            ? ['image_url' => null, 'price' => null, 'is_standard' => true]
            : null;
    }

    public static function column(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + ($number - 1) % 26).$name;
        }

        return $name;
    }
}
