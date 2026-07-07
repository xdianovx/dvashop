<?php

namespace App\Services\Media;

use App\Models\ProductCategory;
use App\Support\CatalogText;

class DefaultProductImageService
{
    public const DISK = 'public_default';
    public const DIRECTORY = 'img/products_default';

    /** @var array<int, string> */
    private const EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];

    /** @var array<string, string> */
    private const CATEGORY_IMAGE_KEYS = [
        'arka/karman-zadniaia' => 'arka-karman-zadniaia',
        'arka/peredniaia' => 'arka-peredniaia',
        'arka/vnutrenniaia' => 'arka-vnutrenniaia',
        'arka/vnutrenniaia-universalnaia' => 'arka-vnutrenniaia-universalnaia',
        'arka/zadniaia' => 'arka-zadniaia',
        'lonzeron' => 'lonzeron',
        'penka/bagaznika' => 'penka-bagaznika',
        'penka/perednei-dveri' => 'penka-perednei-dveri',
        'penka/zadnei-dveri' => 'penka-zadnei-dveri',
        'porog' => 'porog',
        'remkomplekt-pola' => 'remkomplekt-pola',
        'torcevaia-zagluska' => 'torcevaia-zagluska',
        'usilitel-soedinitel-porogov' => 'usilitel-soedinitel-porogov',
    ];

    /**
     * @return array{key:string,path:string,url:string,absolute_path:string}|null
     */
    public function forCategory(ProductCategory $category): ?array
    {
        $key = $this->keyForCategory($category);

        if ($key === null) {
            return null;
        }

        return $this->findByKey($key);
    }

    public function keyForCategory(ProductCategory $category): ?string
    {
        foreach ($this->categoryKeys($category) as $categoryKey) {
            $imageKey = self::CATEGORY_IMAGE_KEYS[$categoryKey] ?? null;

            if (is_string($imageKey) && $imageKey !== '') {
                return $imageKey;
            }
        }

        return null;
    }

    /**
     * @return array{key:string,path:string,url:string,absolute_path:string}|null
     */
    public function findByKey(string $key): ?array
    {
        $key = trim($key, " \t\n\r\0\x0B./");

        if ($key === '') {
            return null;
        }

        foreach (self::EXTENSIONS as $extension) {
            $relativePath = self::DIRECTORY.'/'.$key.'.'.$extension;
            $absolutePath = public_path($relativePath);

            if (! is_file($absolutePath)) {
                continue;
            }

            return [
                'key' => $key,
                'path' => $relativePath,
                'url' => asset($relativePath),
                'absolute_path' => $absolutePath,
            ];
        }

        return null;
    }

    public function urlForPath(?string $path): ?string
    {
        $path = $this->normalizeDefaultPath($path);

        if ($path === null) {
            return null;
        }

        return is_file(public_path($path)) ? asset($path) : null;
    }

    private function normalizeDefaultPath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return null;
        }

        $path = ltrim($path, '/');

        if (! str_starts_with($path, self::DIRECTORY.'/')) {
            return null;
        }

        return $path;
    }

    /** @return array<int, string> */
    private function categoryKeys(ProductCategory $category): array
    {
        $fullSlug = trim((string) ($category->full_slug ?: $category->slug), '/');
        $slug = trim((string) $category->slug, '/');
        $titleSlug = CatalogText::slug($category->title, 'category', 100);
        $fullTitleSlug = $this->slugPathFromFullTitle($category->full_title ?? null);

        return array_values(array_unique(array_filter([
            $fullSlug,
            $fullTitleSlug,
            $slug,
            $titleSlug,
        ], static fn (string $key): bool => $key !== '')));
    }

    private function slugPathFromFullTitle(?string $fullTitle): string
    {
        $fullTitle = CatalogText::plain($fullTitle, 250);

        if ($fullTitle === '') {
            return '';
        }

        return implode('/', array_values(array_filter(array_map(
            static fn (string $segment): string => CatalogText::slug($segment, 'category', 80),
            preg_split('#\s*/\s*#u', $fullTitle) ?: [],
        ), static fn (string $segment): bool => $segment !== '')));
    }
}
