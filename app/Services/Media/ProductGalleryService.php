<?php

namespace App\Services\Media;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogRelationIdNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

class ProductGalleryService
{
    public function __construct(
        private readonly DefaultProductImageService $defaultImages,
    ) {}

    /**
     * @param  array<int, string>  $paths
     * @return Collection<int, ProductImage>
     */
    public function attachManualImages(Product $product, array $paths, ?string $alt = null): Collection
    {
        $paths = collect($paths)
            ->filter(static fn (mixed $path): bool => is_string($path) && trim($path) !== '')
            ->map(static fn (string $path): string => trim($path))
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            return collect();
        }

        $makeFirstMain = ! $this->productHasMainImage($product);
        $originalImages = $product->images()
            ->get(['id', 'is_main', 'is_visible'])
            ->keyBy(fn (ProductImage $image): int|string => $image->getKey());

        try {
            return $paths
                ->map(function (string $path, int $index) use ($product, $alt, $makeFirstMain): ProductImage {
                    return $this->attachManualImage(
                        product: $product,
                        path: $path,
                        alt: $alt,
                        makeMain: $makeFirstMain && $index === 0,
                    );
                })
                ->filter(static fn (ProductImage $image): bool => $image->exists)
                ->unique(static fn (ProductImage $image): int|string => $image->getKey())
                ->values();
        } catch (Throwable $exception) {
            $this->compensateFailedManualImageBatch($product, $originalImages, $paths);

            throw $exception;
        }
    }

    public function attachManualImage(Product $product, string $path, ?string $alt = null, ?bool $makeMain = null): ProductImage
    {
        $product->loadMissing('defaultVariant');

        $hasMain = $this->productHasMainImage($product);
        $makeMain ??= ! $hasMain;

        $image = ProductImage::query()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $product->defaultVariant?->getKey(),
            'disk' => 'public',
            'path' => $path,
            'source_type' => ProductImage::SOURCE_MANUAL,
            'is_default' => false,
            'is_visible' => true,
            'is_main' => $makeMain,
            'position' => $this->nextPosition($product),
            'alt' => $alt ?: $product->title,
        ]);

        if ($image->exists && ProductImage::query()->whereKey($image->getKey())->exists()) {
            return $image->refresh();
        }

        $duplicate = is_string($image->checksum) && $image->checksum !== ''
            ? ProductImage::query()
                ->where('product_id', $product->getKey())
                ->where('checksum', $image->checksum)
                ->first()
            : null;

        if ($duplicate instanceof ProductImage) {
            if ($makeMain && ! $duplicate->is_main) {
                return $this->makeMain($duplicate);
            }

            return $duplicate;
        }

        throw new RuntimeException('Не удалось сохранить загруженное изображение товара.');
    }

    public function prepareImageForSave(ProductImage $image): void
    {
        $relationIds = app(CatalogRelationIdNormalizer::class);
        $productId = $relationIds->positive($image->product_id, 'product_id');
        $variantId = $relationIds->nullablePositive($image->product_variant_id, 'product_variant_id');
        $image->product_id = $productId;
        $image->product_variant_id = $variantId;

        if ($image->exists && $image->isDirty('product_id')) {
            throw ValidationException::withMessages([
                'product_id' => 'Нельзя переносить существующее изображение между товарами.',
            ]);
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

        if (! $product instanceof Product) {
            throw ValidationException::withMessages([
                'product_id' => 'Товар изображения не существует.',
            ]);
        }

        if ($variantId !== null) {
            $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();

            if (! $variant instanceof ProductVariant
                || $relationIds->positive($variant->product_id, 'product_id') !== $productId) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'Вариант изображения должен принадлежать тому же товару.',
                ]);
            }
        }

        $images = $product->images()->orderBy('id')->lockForUpdate()->get();

        if ($image->exists && ! $images->contains('id', $image->getKey())) {
            throw ValidationException::withMessages([
                'image' => 'Изображение товара было изменено или удалено. Обновите страницу.',
            ]);
        }

        if (! $image->is_main) {
            return;
        }

        $product->images()
            ->when($image->exists, fn ($query) => $query->whereKeyNot($image->getKey()))
            ->where('is_main', true)
            ->update(['is_main' => false]);
    }

    /**
     * @param  Collection<int|string, ProductImage>  $originalImages
     * @param  Collection<int, string>  $sourcePaths
     */
    private function compensateFailedManualImageBatch(
        Product $product,
        Collection $originalImages,
        Collection $sourcePaths,
    ): void {
        $originalIds = $originalImages->keys()->all();
        $newImages = $product->images()
            ->when($originalIds !== [], fn ($images) => $images->whereNotIn('id', $originalIds))
            ->get();

        foreach ($newImages as $image) {
            if (! $sourcePaths->containsStrict($image->path)) {
                $image->deleteFiles();
            }

            $image->deleteFromGalleryWorkflow();
        }

        foreach ($originalImages as $originalImage) {
            ProductImage::query()
                ->whereKey($originalImage->getKey())
                ->update([
                    'is_main' => $originalImage->is_main,
                    'is_visible' => $originalImage->is_visible,
                ]);
        }

        $product->unsetRelation('images');
        $product->unsetRelation('mainImage');
    }

    public function ensureDefaultImage(Product $product, bool $makeMain = false): ?ProductImage
    {
        $product->loadMissing(['partType', 'category', 'defaultVariant']);

        $default = $this->defaultImages->forProduct($product);

        if ($default === null) {
            return null;
        }

        /** @var ProductImage|null $image */
        $image = ProductImage::query()
            ->where('product_id', $product->getKey())
            ->where('source_type', ProductImage::SOURCE_DEFAULT)
            ->where('is_default', true)
            ->where('path', $default['path'])
            ->first();

        $hasMain = $this->productHasMainImage($product);

        if ($image instanceof ProductImage) {
            $image->forceFill([
                'product_variant_id' => $image->product_variant_id ?: $product->defaultVariant?->getKey(),
                'disk' => DefaultProductImageService::DISK,
                'path' => $default['path'],
                'source_type' => ProductImage::SOURCE_DEFAULT,
                'is_default' => true,
                'is_visible' => true,
                'is_main' => $makeMain || (! $hasMain && ! $image->is_main),
                'alt' => $image->alt ?: $product->title,
            ])->save();

            return $image->refresh();
        }

        return ProductImage::query()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $product->defaultVariant?->getKey(),
            'disk' => DefaultProductImageService::DISK,
            'path' => $default['path'],
            'source_url' => null,
            'source_type' => ProductImage::SOURCE_DEFAULT,
            'is_default' => true,
            'is_visible' => true,
            'is_main' => $makeMain || ! $hasMain,
            'position' => $this->nextPosition($product),
            'alt' => $product->title,
        ])->refresh();
    }

    public function makeDefaultMain(Product $product): ProductImage
    {
        $image = $this->ensureDefaultImage($product, true);

        if (! $image instanceof ProductImage) {
            throw new RuntimeException('Для товара не найдено дефолтное изображение.');
        }

        return $this->makeMain($image);
    }

    public function resetToDefault(Product $product): ProductImage
    {
        return DB::transaction(function () use ($product): ProductImage {
            $lockedProduct = Product::query()->whereKey($product)->lockForUpdate()->firstOrFail();
            $defaultVariant = $lockedProduct->variants()
                ->where('is_default', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            $lockedProduct->setRelation('defaultVariant', $defaultVariant);
            $lockedProduct->load(['partType', 'category']);
            $lockedImages = $lockedProduct->images()->orderBy('id')->lockForUpdate()->get();
            $defaultSource = $this->defaultImages->forProduct($lockedProduct);

            if ($defaultSource === null) {
                throw new RuntimeException('Для товара не найдено дефолтное изображение. Галерея не изменена.');
            }

            $existingDefault = $lockedImages->first(fn (ProductImage $image): bool => $image->path === $defaultSource['path']
                && ($image->source_type === ProductImage::SOURCE_DEFAULT
                    || $image->is_default
                    || $image->disk === DefaultProductImageService::DISK));

            foreach ($lockedImages as $image) {
                if ($existingDefault instanceof ProductImage && $image->is($existingDefault)) {
                    continue;
                }

                $deletedImage = clone $image;
                $image->deleteFromGalleryWorkflow();
                DB::afterCommit(fn (): mixed => $deletedImage->deleteFiles());
            }

            $default = $this->persistResetDefault($lockedProduct, $defaultSource, $existingDefault);
            $remaining = $lockedProduct->images()->orderBy('id')->lockForUpdate()->get();

            if ($remaining->count() !== 1
                || ! $remaining->first()?->is($default)
                || $remaining->where('is_main', true)->count() !== 1
                || ! $default->is_visible) {
                throw new RuntimeException('Не удалось атомарно восстановить единственное главное изображение товара.');
            }

            return $default->refresh();
        });
    }

    /**
     * @param  array{key:string,path:string,url:string,absolute_path:string}  $defaultSource
     */
    protected function persistResetDefault(
        Product $product,
        array $defaultSource,
        ?ProductImage $existingDefault,
    ): ProductImage {
        $attributes = [
            'product_id' => $product->getKey(),
            'product_variant_id' => $product->defaultVariant?->getKey(),
            'disk' => DefaultProductImageService::DISK,
            'path' => $defaultSource['path'],
            'source_url' => null,
            'source_type' => ProductImage::SOURCE_DEFAULT,
            'is_default' => true,
            'is_visible' => true,
            'is_main' => true,
            'position' => 0,
            'alt' => $existingDefault?->alt ?: $product->title,
        ];

        if ($existingDefault instanceof ProductImage) {
            $existingDefault->forceFill($attributes)->saveQuietly();

            return $existingDefault->refresh();
        }

        $image = new ProductImage;
        $image->forceFill($attributes)->saveQuietly();

        return $image->refresh();
    }

    public function makeMain(ProductImage $image): ProductImage
    {
        return DB::transaction(function () use ($image): ProductImage {
            [$product, , $target] = $this->lockGalleryForImage($image);
            $product->images()
                ->whereKeyNot($target->getKey())
                ->where('is_main', true)
                ->update(['is_main' => false]);
            $target->forceFill([
                'is_main' => true,
                'is_visible' => true,
            ])->saveQuietly();

            return $target->refresh();
        });
    }

    public function setVisible(ProductImage $image, bool $visible): ProductImage
    {
        return DB::transaction(function () use ($image, $visible): ProductImage {
            [, , $target] = $this->lockGalleryForImage($image);

            if (! $visible && $target->is_main) {
                throw new LogicException('Главное изображение нельзя скрыть. Сначала выберите другое главное изображение или удалите текущее.');
            }

            $target->forceFill(['is_visible' => $visible])->saveQuietly();

            return $target->refresh();
        });
    }

    public function deleteImage(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            [$product, $images, $target] = $this->lockGalleryForImage($image);
            $deletedImage = clone $target;
            $wasMain = (bool) $target->is_main;
            $target->deleteFromGalleryWorkflow();

            if ($wasMain) {
                $product->images()->where('is_main', true)->update(['is_main' => false]);
                $fallback = $images
                    ->reject(fn (ProductImage $candidate): bool => $candidate->is($target))
                    ->filter(fn (ProductImage $candidate): bool => $candidate->is_visible)
                    ->sortBy(fn (ProductImage $candidate): string => sprintf(
                        '%02d:%010d:%010d',
                        match ($candidate->source_type) {
                            ProductImage::SOURCE_MANUAL => 0,
                            ProductImage::SOURCE_IMPORT => 1,
                            ProductImage::SOURCE_DEFAULT => 2,
                            default => 3,
                        },
                        (int) $candidate->position,
                        (int) $candidate->getKey(),
                    ))
                    ->first();

                if ($fallback instanceof ProductImage) {
                    $fallback->forceFill(['is_main' => true, 'is_visible' => true])->saveQuietly();
                }
            }

            DB::afterCommit(fn (): mixed => $deletedImage->deleteFiles());
        });
    }

    public function nextPosition(Product $product): int
    {
        return (int) $product->images()->max('position') + 1;
    }

    public function sourceLabel(?string $sourceType): string
    {
        return ProductImage::sourceTypeLabel($sourceType);
    }

    private function productHasMainImage(Product $product): bool
    {
        return $product->images()
            ->where('is_main', true)
            ->where('is_visible', true)
            ->exists();
    }

    /** @return array{Product, Collection<int, ProductImage>, ProductImage} */
    private function lockGalleryForImage(ProductImage $image): array
    {
        $relationIds = app(CatalogRelationIdNormalizer::class);
        $productId = $relationIds->positive(
            $image->getRawOriginal('product_id') ?? $image->product_id,
            'product_id',
        );
        $variantId = $relationIds->nullablePositive(
            $image->getRawOriginal('product_variant_id') ?? $image->product_variant_id,
            'product_variant_id',
        );
        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

        if (! $product instanceof Product) {
            throw ValidationException::withMessages([
                'product_id' => 'Товар изображения не существует.',
            ]);
        }

        if ($variantId !== null) {
            $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();

            if (! $variant instanceof ProductVariant
                || $relationIds->positive($variant->product_id, 'product_id') !== $productId) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'Вариант изображения должен принадлежать тому же товару.',
                ]);
            }
        }

        $images = $product->images()->orderBy('id')->lockForUpdate()->get();
        $target = $images->firstWhere('id', $image->getKey());

        if (! $target instanceof ProductImage) {
            throw ValidationException::withMessages([
                'image' => 'Изображение товара было изменено или удалено. Обновите страницу.',
            ]);
        }

        return [$product, $images, $target];
    }
}
