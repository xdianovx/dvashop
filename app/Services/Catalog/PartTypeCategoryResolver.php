<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\RequiredCatalogCategoryMissingException;
use App\Models\PartType;
use App\Models\ProductCategory;

class PartTypeCategoryResolver
{
    private const FALLBACK_CATEGORY = 'kuzovnye-detali/remontnye-elementy-kuzova';

    /** @var array<string, string> */
    private const CATEGORY_BY_PART_TYPE = [
        'porog' => 'kuzovnye-detali/remontnye-elementy-kuzova/porogi',
        'arka' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'arka/zadniaia' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'arka/peredniaia' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'arka/vnutrenniaia' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'arka/vnutrenniaia-universalnaia' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'arka/karman-zadniaia' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        'penka' => 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki',
        'penka/zadnei-dveri' => 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki',
        'penka/perednei-dveri' => 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki',
        'penka/bagazhnika' => 'kuzovnye-detali/remontnye-elementy-kuzova/pennye-vstavki',
        'lonzheron' => 'kuzovnye-detali/remontnye-elementy-kuzova/lonzherony',
        'remkomplekt-pola' => 'kuzovnye-detali/remontnye-elementy-kuzova/remkomplekty-pola',
        'tortsevaia-zaglushka' => 'kuzovnye-detali/remontnye-elementy-kuzova/zaglushki',
        'usilitel' => 'kuzovnye-detali/remontnye-elementy-kuzova/usiliteli',
        'usilitel/soedinitel-porogov' => 'kuzovnye-detali/remontnye-elementy-kuzova/usiliteli',
    ];

    /** @var array<string, ProductCategory> */
    private array $categories = [];

    public function resolve(PartType|string $partType): PartTypeCategoryResolution
    {
        $partTypePath = trim($partType instanceof PartType ? $partType->full_slug : $partType, '/');

        return new PartTypeCategoryResolution(
            category: $this->category($this->categoryPathFor($partTypePath)),
            usedFallback: $this->usesFallback($partTypePath),
        );
    }

    public function categoryPathFor(PartType|string $partType): string
    {
        $partTypePath = trim($partType instanceof PartType ? $partType->full_slug : $partType, '/');

        return self::CATEGORY_BY_PART_TYPE[$partTypePath] ?? self::FALLBACK_CATEGORY;
    }

    public function usesFallback(PartType|string $partType): bool
    {
        $partTypePath = trim($partType instanceof PartType ? $partType->full_slug : $partType, '/');

        return ! array_key_exists($partTypePath, self::CATEGORY_BY_PART_TYPE);
    }

    public function resetLocalCache(): void
    {
        $this->categories = [];
    }

    private function category(string $fullSlug): ProductCategory
    {
        if (isset($this->categories[$fullSlug])) {
            return $this->categories[$fullSlug];
        }

        $category = ProductCategory::query()->where('full_slug', $fullSlug)->first();

        if (! $category instanceof ProductCategory) {
            throw RequiredCatalogCategoryMissingException::forPath($fullSlug);
        }

        return $this->categories[$fullSlug] = $category;
    }
}
