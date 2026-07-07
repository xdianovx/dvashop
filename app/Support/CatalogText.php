<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CatalogText
{
    public static function slug(?string $value, string $fallback = 'item', int $maxLength = 120): string
    {
        $source = self::plain($value, max(250, $maxLength * 2));
        $slug = Str::slug($source);

        if ($slug === '') {
            $hashSource = $source !== '' ? $source : $fallback;
            $slug = Str::slug($fallback) ?: 'item';
            $slug .= '-'.substr(sha1($hashSource), 0, 8);
        }

        return self::limitStable($slug, $maxLength);
    }

    public static function normKey(?string $value, string $fallback = 'item', int $maxLength = 120): string
    {
        return self::slug($value, $fallback, $maxLength);
    }

    public static function plain(mixed $value, int $maxLength = 250): string
    {
        $value = (string) $value;
        $value = str_replace("\xc2\xa0", ' ', $value);
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value;
        $value = trim($value);
        $maxLength = max(16, $maxLength);

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return trim(mb_substr($value, 0, $maxLength));
    }

    /** @param array<int, string|null> $parts */
    public static function stableKey(array $parts, string $separator = ':', int $maxLength = 240, string $fallback = 'item'): string
    {
        $normalized = array_values(array_filter(array_map(
            static fn (?string $part): string => self::normKey($part, $fallback, 80),
            $parts,
        ), static fn (string $part): bool => $part !== ''));

        $value = implode($separator, $normalized);

        if ($value === '') {
            $value = self::normKey($fallback);
        }

        return self::limitStable($value, $maxLength);
    }

    /** @param array<int, string|null> $segments */
    public static function slugPath(array $segments, int $maxLength = 250): string
    {
        $path = implode('/', array_values(array_filter(array_map(
            static fn (?string $segment): string => trim(self::plain($segment, 250), '/'),
            $segments,
        ), static fn (string $segment): bool => $segment !== '')));

        return self::limitStable($path !== '' ? $path : 'item', $maxLength);
    }

    public static function limitStable(string $value, int $maxLength = 120): string
    {
        $value = trim($value, " \t\n\r\0\x0B-_/:");
        $maxLength = max(16, $maxLength);

        if ($value === '') {
            $value = 'item';
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $hash = substr(sha1($value), 0, 10);
        $prefixLength = max(1, $maxLength - 11);
        $prefix = mb_substr($value, 0, $prefixLength);
        $prefix = trim($prefix, " \t\n\r\0\x0B-_/:");

        return ($prefix !== '' ? $prefix : 'item').'-'.$hash;
    }
}
