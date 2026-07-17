<?php

namespace App\Services\Catalog;

use App\Support\CatalogText;

final class LegacyTechnicalCategoryMap
{
    /** @var array<string, string> */
    private const PART_TYPE_BY_PATH = [
        'порог' => 'porog',
        'арка' => 'arka',
        'арка / задняя' => 'arka/zadniaia',
        'арка / передняя' => 'arka/peredniaia',
        'арка / внутренняя' => 'arka/vnutrenniaia',
        'арка / внутренняя универсальная' => 'arka/vnutrenniaia-universalnaia',
        'арка / карман задняя' => 'arka/karman-zadniaia',
        'пенка' => 'penka',
        'пенка / задней двери' => 'penka/zadnei-dveri',
        'пенка / передней двери' => 'penka/perednei-dveri',
        'пенка / багажника' => 'penka/bagazhnika',
        'лонжерон' => 'lonzheron',
        'ремкомплект пола' => 'remkomplekt-pola',
        'торцевая заглушка' => 'tortsevaia-zaglushka',
        'усилитель' => 'usilitel',
        'усилитель / соединитель порогов' => 'usilitel/soedinitel-porogov',
    ];

    /** @var array<int, string> */
    private const TECHNICAL_ROOTS = [
        'порог',
        'арка',
        'пенка',
        'лонжерон',
        'ремкомплект пола',
        'торцевая заглушка',
        'усилитель',
    ];

    /** @var array<int, string> */
    private const SUSPICIOUS_WORDS = [
        'кузов',
        'порог',
        'арка',
        'пенка',
        'лонжерон',
        'ремкомплект',
        'заглушка',
        'усилитель',
        'соединитель',
    ];

    public function normalizePath(string $path): string
    {
        $path = str_replace(["\r\n", "\r", "\n"], ' ', $path);
        $segments = preg_split('/\s*\/\s*/u', $path) ?: [];
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => mb_strtolower(CatalogText::plain($segment, 255)),
            $segments,
        ), static fn (string $segment): bool => $segment !== ''));

        return implode(' / ', $segments);
    }

    public function partTypePath(string $categoryPath): ?string
    {
        return self::PART_TYPE_BY_PATH[$this->normalizePath($categoryPath)] ?? null;
    }

    public function isUnknownChildUnderKnownRoot(string $categoryPath): bool
    {
        $normalized = $this->normalizePath($categoryPath);
        $segments = explode(' / ', $normalized);

        return count($segments) > 1
            && in_array($segments[0], self::TECHNICAL_ROOTS, true)
            && ! array_key_exists($normalized, self::PART_TYPE_BY_PATH);
    }

    public function isUnderKnownTechnicalRoot(string $categoryPath): bool
    {
        $segments = explode(' / ', $this->normalizePath($categoryPath));

        return in_array($segments[0] ?? '', self::TECHNICAL_ROOTS, true);
    }

    public function looksSuspicious(string $categoryPath): bool
    {
        $normalized = $this->normalizePath($categoryPath);

        foreach (self::SUSPICIOUS_WORDS as $word) {
            if (str_contains($normalized, $word)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function normalizedSegments(string $categoryPath): array
    {
        return explode(' / ', $this->normalizePath($categoryPath));
    }
}
