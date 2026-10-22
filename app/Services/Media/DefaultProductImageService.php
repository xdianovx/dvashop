<?php

namespace App\Services\Media;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Catalog\PartTypeDefinitionRegistry;
use App\Services\Catalog\PartTypeTreeService;
use App\Support\CatalogText;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DefaultProductImageService
{
    public const DISK = 'public_default';
    public const DIRECTORY = 'img/products_default';

    public const STORAGE_DIRECTORY = 'catalog/defaults';

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
    public function forPartType(PartType $partType): ?array
    {
        $key = $this->keyForPartType($partType);

        return $key === null ? null : $this->findByKey($key);
    }

    public function keyForPartType(PartType $partType): ?string
    {
        $key = trim((string) $partType->default_image_key);

        if ($key !== '') {
            return $key;
        }
        $definitions = app(PartTypeDefinitionRegistry::class)->flattened(app(PartTypeTreeService::class));

        return $definitions[$partType->full_slug]['default_image_key'] ?? null;
    }

    /**
     * @return array{key:string,path:string,url:string,absolute_path:string}|null
     */
    public function forProduct(Product $product): ?array
    {
        if ($product->part_type_id !== null) {
            $product->loadMissing('partType');

            return $product->partType instanceof PartType
                ? $this->forPartType($product->partType)
                : null;
        }

        $product->loadMissing('category');

        return $product->category instanceof ProductCategory
            ? $this->forCategory($product->category)
            : null;
    }

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
        $definitions = app(PartTypeDefinitionRegistry::class)->flattened(app(PartTypeTreeService::class));
        foreach ($this->categoryKeys($category) as $categoryKey) {
            $imageKey = $definitions[$categoryKey]['default_image_key'] ?? self::CATEGORY_IMAGE_KEYS[$categoryKey] ?? null;

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
        $key = trim($key);
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $key)) {
            return null;
        }
        $storage = Storage::disk('public');
        foreach (self::EXTENSIONS as $extension) {
            $path = self::STORAGE_DIRECTORY.'/'.$key.'.'.$extension;
            if ($storage->exists($path)) {
                return $this->reference($key, $path);
            }
        }
        // Copy a shipped/previously replaced default once into persistent media storage.
        foreach (self::EXTENSIONS as $extension) {
            $original = public_path(self::DIRECTORY.'/'.$key.'.'.$extension);
            if (is_file($original)) {
                $path = self::STORAGE_DIRECTORY.'/'.$key.'.'.$extension;
                $this->createOnce($storage->path($path), file_get_contents($original));
                return $this->reference($key, $path);
            }
        }
        $path = self::STORAGE_DIRECTORY.'/'.$key.'.png';
        $this->createOnce($storage->path($path), $this->placeholderPng());

        return $this->reference($key, $path);
    }

    private function reference(string $key, string $path): array
    {
        $storage = Storage::disk('public');

        clearstatcache(true, $storage->path($path));
        $version = $storage->lastModified($path).'-'.$storage->size($path);

        return ['key' => $key, 'path' => $path, 'url' => $storage->url($path).'?v='.$version, 'absolute_path' => $storage->path($path)];
    }

    /** Atomic creation without replacing a file, even with concurrent import workers. */
    private function createOnce(string $target, string $contents): void
    {
        File::ensureDirectoryExists(dirname($target));
        $temporary = tempnam(dirname($target), '.default-');
        if ($temporary === false) {
            throw new RuntimeException('Не удалось создать дефолтное изображение.');
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new RuntimeException('Не удалось сохранить дефолтное изображение.');
            }
            chmod($temporary, 0644);
            if (! @link($temporary, $target) && ! is_file($target)) {
                throw new RuntimeException('Не удалось опубликовать дефолтное изображение.');
            }
        } finally {
            unlink($temporary);
        }
    }

    private function placeholderPng(): string
    {
        $image = imagecreatetruecolor(640, 480);
        $background = imagecolorallocate($image, 243, 244, 246);
        $foreground = imagecolorallocate($image, 156, 163, 175);
        imagefill($image, 0, 0, $background);
        imagesetthickness($image, 8);
        imagerectangle($image, 190, 150, 450, 330, $foreground);
        imageellipse($image, 320, 240, 100, 100, $foreground);
        imagefilledrectangle($image, 255, 125, 385, 150, $foreground);
        ob_start();
        try {
            imagepng($image);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    public function urlForPath(?string $path): ?string
    {
        $path = $this->normalizeDefaultPath($path);
        if ($path === null) {
            return null;
        }
        // Old database references resolve through the same shared persistent file.
        return $this->findByKey(pathinfo($path, PATHINFO_FILENAME))['url'] ?? null;
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

        if (! str_starts_with($path, self::DIRECTORY.'/') && ! str_starts_with($path, self::STORAGE_DIRECTORY.'/')) {
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
