<?php

namespace App\Services\Catalog;

use Illuminate\Validation\ValidationException;

final class CatalogRelationIdNormalizer
{
    public function nullablePositive(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = $this->normalize($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => 'Идентификатор связи должен быть положительным целым числом или пустым значением.',
            ]);
        }

        return $normalized;
    }

    public function positive(mixed $value, string $field): int
    {
        $normalized = $value === null || $value === '' ? null : $this->normalize($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => 'Идентификатор связи должен быть положительным целым числом.',
            ]);
        }

        return $normalized;
    }

    private function normalize(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        $digits = ltrim($value, '0');

        if ($digits === '') {
            return null;
        }

        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }

        return (int) $digits;
    }
}
