<?php

namespace App\Services\Import;

use App\Enums\ImportLogLevel;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Jobs\DownloadProductImageJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\ImportLogger;
use App\Services\ImportRunStats;
use App\Services\ImportStatusService;
use App\Services\Media\ProductGalleryService;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportProductFactory
{
    public function __construct(
        private readonly ImportRunStats $stats,
        private readonly ImportStatusService $statusService,
        private readonly ProductGalleryService $gallery,
        private readonly ImportLogger $logger,
        private readonly CatalogImportPriceResolver $priceResolver,
        private readonly CatalogImportProductDefaults $productDefaults,
    ) {}

    /** @var array<string, bool> */
    private array $missingDefaultImageWarnings = [];

    /** @var array<string, bool> */
    private array $missingReferencePriceWarnings = [];

    /** @var array<string, bool> */
    private array $missingProductImageUrlWarnings = [];

    /**
     * @param  array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string}  $detailHeader
     */
    public function createOrUpdateFromCell(
        ImportRun $run,
        VehicleGeneration $generation,
        PartType $partType,
        ProductCategory $storeCategory,
        array $detailHeader,
        string $cellValue,
        ?string $imageUrl = null,
    ): Product {
        $vehicle = $generation->loadMissing('model.make');
        $source = $this->sourceFor($run);
        $productTitle = $this->productTitle($partType, $vehicle);
        $importKey = $this->importKey($vehicle, $partType, $source);
        $slug = $this->stableSlug($vehicle, $partType, $source, $productTitle);
        $referencePrice = $this->priceResolver->resolve($partType);

        if ($referencePrice === null) {
            $this->warnMissingReferencePriceOnce($run, $partType);
        }

        /** @var Product $product */
        $product = Product::query()->withTrashed()->firstOrNew(['import_key' => $importKey]);
        $wasCreated = ! $product->exists;
        $initializeDefaults = $wasCreated && $source === 'catalog';
        $canonicalTemplate = $initializeDefaults ? $this->productDefaults->canonicalTemplate() : null;

        $productAttributes = [
            'product_type' => ProductType::AutoPart,
            'part_type_id' => $partType->getKey(),
            'product_category_id' => $storeCategory->getKey(),
            'title' => $productTitle,
            'slug' => $slug,
            'status' => ProductStatus::Active,
            'import_source' => $source,
            'last_import_run_id' => (string) $run->getKey(),
        ];

        if ($wasCreated) {
            $productAttributes['stock_status'] = StockStatus::InStock;
            $productAttributes['price'] = $referencePrice['price'] ?? null;
            $productAttributes['old_price'] = $referencePrice['old_price'] ?? null;
        }

        if ($initializeDefaults) {
            $productAttributes['product_option_template_id'] = $canonicalTemplate?->getKey();
            $productAttributes['description'] = $this->productDefaults->description($partType, $vehicle);
        }

        [$product, $variant] = DB::transaction(function () use (
            $product,
            $productAttributes,
            $wasCreated,
            $initializeDefaults,
            $run,
            $referencePrice,
            $canonicalTemplate,
            $partType,
            $storeCategory,
            $generation,
        ): array {
            if (! $wasCreated && $product->trashed()) {
                $product->restore();
            }

            $product->fill($productAttributes);
            $wasChanged = $product->isDirty();
            $product->save();

            if ($wasCreated) {
                $this->stats->increment($run, 'created_products');
            } elseif ($wasChanged) {
                $this->stats->increment($run, 'updated_products');
            }

            $variant = ProductVariant::query()->firstOrNew([
                'product_id' => $product->getKey(),
                'is_default' => true,
            ]);

            if (! $variant->exists) {
                $variant->fill([
                    'title' => 'Основной',
                    'price' => $referencePrice['price'] ?? 0,
                    'old_price' => $referencePrice['old_price'] ?? null,
                    'stock_status' => StockStatus::InStock,
                    'is_active' => true,
                ])->save();
            }

            if ($initializeDefaults && $canonicalTemplate !== null) {
                $this->productDefaults->initialize($product, $canonicalTemplate);
                $variant = $product->defaultVariant()->firstOrFail();
            }

            $product->setRelation('partType', $partType);
            $product->setRelation('category', $storeCategory);
            $product->setRelation('defaultVariant', $variant);

            ProductFitment::query()->firstOrCreate(
                [
                    'product_id' => $product->getKey(),
                    'vehicle_generation_id' => $generation->getKey(),
                ],
                [
                    'note' => null,
                    'is_primary' => true,
                ]
            );

            return [$product, $variant];
        });

        $imageUrl ??= $this->isUrl($cellValue) ? trim($cellValue) : null;

        if ($imageUrl !== null) {
            $this->promoteExistingImportImageIfPresent($product, $imageUrl);

            if ($this->shouldQueueProductImage($product, $imageUrl, $run)) {
                $this->statusService->imageQueued($run);
                DownloadProductImageJob::dispatch($product->getKey(), $imageUrl, $run->getKey())->onQueue('imports-images');
            }
        } elseif ($this->isPositiveAvailabilityCell($cellValue)) {
            $this->warnImportImageUrlDisappearedOnce($product, $run, $partType, $storeCategory);
            $this->attachDefaultImage($product, $partType, $run);
        }

        return $product->refresh();
    }

    public function archiveMissingProducts(ImportRun $run): int
    {
        if ($run->total_rows <= 0 || ! $run->status?->isRowsRunning()) {
            return 0;
        }

        $archived = Product::query()
            ->where('import_source', $this->sourceFor($run))
            ->whereNotNull('import_key')
            ->where(function ($query) use ($run): void {
                $query
                    ->whereNull('last_import_run_id')
                    ->orWhere('last_import_run_id', '!=', (string) $run->getKey());
            })
            ->where('status', '!=', ProductStatus::Archived->value)
            ->update(['status' => ProductStatus::Archived->value]);

        $this->stats->increment($run, 'archived_products', $archived);

        return $archived;
    }

    public function productTitle(PartType $partType, VehicleGeneration $generation): string
    {
        $generation->loadMissing('model.make');

        $title = trim(implode(' ', array_filter([
            $this->partTypeTitleForProduct($partType),
            'для',
            $generation->model?->make?->title,
            $generation->model?->title,
            $generation->title,
            $generation->years_label,
            $generation->body,
        ], static fn (?string $part): bool => trim((string) $part) !== '')));

        return CatalogText::plain($title, 250);
    }

    public function importKey(VehicleGeneration $generation, PartType $partType, string $source = 'catalog'): string
    {
        $generation->loadMissing('model.make');

        return CatalogText::stableKey([
            CatalogText::normKey($source, 'catalog', 60) ?: 'catalog',
            $generation->model?->make?->norm_key,
            $generation->model?->norm_key,
            $generation->norm_key,
            ...$this->partTypeImportKeySegments($partType),
        ], ':', 240, 'catalog');
    }

    /** @return array<int, string> */
    private function partTypeImportKeySegments(PartType $partType): array
    {
        $path = CatalogText::plain($partType->full_slug ?: $partType->slug ?: $partType->title, 250);
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => trim($segment),
            explode('/', $path),
        ), static fn (string $segment): bool => $segment !== ''));

        return $segments !== [] ? $segments : [$partType->title];
    }

    public function stableSlug(VehicleGeneration $generation, PartType $partType, string $source = 'catalog', ?string $productTitle = null): string
    {
        $normalizedSource = CatalogText::normKey($source, 'catalog', 60) ?: 'catalog';
        $titleSlug = CatalogText::slug($productTitle ?: $this->productTitle($partType, $generation), 'product', 120);
        $identity = Str::after($this->importKey($generation, $partType, $source), $normalizedSource.':');
        $base = $normalizedSource === 'catalog' ? $titleSlug : $normalizedSource.'-'.$titleSlug;

        return CatalogText::limitStable($base.'-'.substr(sha1($identity), 0, 8), 150);
    }

    private function partTypeTitleForProduct(PartType $partType): string
    {
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => CatalogText::plain($segment, 250),
            preg_split('#\s*/\s*#u', (string) ($partType->full_title ?: $partType->title)) ?: [],
        ), static fn (string $segment): bool => $segment !== ''));

        $title = implode(' ', array_map(
            fn (string $segment, int $index): string => $index === 0 ? $segment : $this->lowerFirst($segment),
            $segments,
            array_keys($segments),
        ));

        return CatalogText::plain($title !== '' ? $title : $partType->title, 250);
    }

    private function lowerFirst(string $value): string
    {
        $value = CatalogText::plain($value, 250);

        if ($value === '') {
            return '';
        }

        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    public function isUrl(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_URL) !== false;
    }

    private function attachDefaultImage(Product $product, PartType $partType, ImportRun $run): void
    {
        $image = $this->gallery->ensureDefaultImage($product);

        if (! $image instanceof ProductImage) {
            $this->warnMissingDefaultImageOnce($run, $partType);
        }
    }

    private function warnImportImageUrlDisappearedOnce(Product $product, ImportRun $run, PartType $partType, ProductCategory $storeCategory): void
    {
        if (! $product->images()
            ->where('source_type', ProductImage::SOURCE_IMPORT)
            ->whereNotNull('source_url')
            ->exists()) {
            return;
        }

        $key = $run->getKey().':'.$product->getKey().':missing-url';

        if (isset($this->missingProductImageUrlWarnings[$key])) {
            return;
        }

        $this->missingProductImageUrlWarnings[$key] = true;

        $this->logger->warning($run, 'URL изображения товара исчез из файла импорта, существующие изображения сохранены', [
            'product_id' => $product->getKey(),
            'import_key' => $product->import_key,
            'part_type' => $partType->full_title,
            'store_category' => $storeCategory->full_title,
        ]);
    }

    private function promoteExistingImportImageIfPresent(Product $product, string $url): void
    {
        if ($this->productHasManualMainImage($product)) {
            return;
        }

        /** @var ProductImage|null $existing */
        $existing = $product->images()
            ->where('source_type', ProductImage::SOURCE_IMPORT)
            ->where('source_url', $url)
            ->where('is_visible', true)
            ->first();

        if (! $existing instanceof ProductImage) {
            return;
        }

        $disk = $existing->disk ?: 'public';
        $path = $existing->path;

        if (! is_string($path) || $path === '' || ! Storage::disk($disk)->exists($path)) {
            return;
        }

        $this->gallery->makeMain($existing);
    }

    private function productHasManualMainImage(Product $product): bool
    {
        return $product->images()
            ->where('is_main', true)
            ->where('is_visible', true)
            ->where('source_type', ProductImage::SOURCE_MANUAL)
            ->exists();
    }

    private function warnMissingReferencePriceOnce(ImportRun $run, PartType $partType): void
    {
        $partTypeKey = $partType->full_slug ?: (string) $partType->getKey();
        $key = $run->getKey().':'.$partTypeKey;

        if (isset($this->missingReferencePriceWarnings[$key])) {
            return;
        }

        $alreadyLogged = ImportLog::query()
            ->where('import_run_id', $run->getKey())
            ->where('level', ImportLogLevel::Warning->value)
            ->where('message', 'Справочная цена для типа детали не найдена')
            ->where('context->part_type_full_slug', $partTypeKey)
            ->exists();

        if ($alreadyLogged) {
            $this->missingReferencePriceWarnings[$key] = true;

            return;
        }

        $this->missingReferencePriceWarnings[$key] = true;

        $this->logger->warning($run, 'Справочная цена для типа детали не найдена', [
            'part_type_id' => $partType->getKey(),
            'part_type' => $partType->full_title ?: $partType->title,
            'part_type_full_slug' => $partTypeKey,
        ]);
    }

    private function warnMissingDefaultImageOnce(ImportRun $run, PartType $partType): void
    {
        $partTypeKey = $partType->full_slug ?: $partType->slug ?: (string) $partType->getKey();
        $key = $run->getKey().':'.$partTypeKey;

        if (isset($this->missingDefaultImageWarnings[$key])) {
            return;
        }

        $alreadyLogged = ImportLog::query()
            ->where('import_run_id', $run->getKey())
            ->where('level', ImportLogLevel::Warning->value)
            ->where('message', 'Дефолтное изображение детали не найдено')
            ->where('context->part_type_full_slug', $partTypeKey)
            ->exists();

        if ($alreadyLogged) {
            $this->missingDefaultImageWarnings[$key] = true;

            return;
        }

        $this->missingDefaultImageWarnings[$key] = true;

        $this->logger->warning($run, 'Дефолтное изображение детали не найдено', [
            'part_type_id' => $partType->getKey(),
            'part_type' => $partType->full_title,
            'part_type_full_slug' => $partTypeKey,
            'default_image_key' => $partType->default_image_key,
        ]);
    }

    private function productHasMainImage(Product $product): bool
    {
        return $product->images()
            ->where('is_main', true)
            ->where('is_visible', true)
            ->exists();
    }

    private function isPositiveAvailabilityCell(string $value): bool
    {
        $normalized = mb_strtolower(str_replace(',', '.', trim($value)));

        return in_array($normalized, ['да', 'yes', 'true'], true) || preg_match('/^1(?:\.0+)?$/', $normalized) === 1;
    }

    /** @var array<string, bool> */
    private array $queuedProductImages = [];

    private function shouldQueueProductImage(Product $product, string $url, ImportRun $run): bool
    {
        $key = $run->getKey().':'.$product->getKey().':'.sha1($url);

        if (isset($this->queuedProductImages[$key])) {
            return false;
        }

        $existing = ProductImage::query()
            ->where('product_id', $product->getKey())
            ->where('source_url', $url)
            ->first();

        if ($existing instanceof ProductImage) {
            $disk = $existing->disk ?: 'public';
            $path = $existing->path;

            if (is_string($path) && $path !== '' && Storage::disk($disk)->exists($path)) {
                return false;
            }
        }

        $this->queuedProductImages[$key] = true;

        return true;
    }

    private function sourceFor(ImportRun $run): string
    {
        return CatalogText::normKey($run->type ?: 'catalog', 'catalog', 60) ?: 'catalog';
    }
}
