<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CatalogText
{
    public static function slug(?string $value, string $fallback = 'item'): string
    {
        $slug = Str::slug((string) $value);

        return $slug !== '' ? $slug : $fallback;
    }

    public static function normKey(?string $value, string $fallback = 'item'): string
    {
        return self::slug($value, $fallback);
    }
}
