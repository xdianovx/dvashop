<?php

namespace App\Services\Import;

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Jobs\DownloadProductImageJob;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\ImportRunStats;
use App\Services\ImportStatusService;
use App\Support\CatalogText;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportProductFactory
{
    public function __construct(
        private readonly ImportRunStats $stats,
        private readonly ImportStatusService $statusService,
    ) {}

    /**
     * @param array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string} $detailHeader
     */
    public function createOrUpdateFromCell(
        ImportRun $run,
        VehicleGeneration $generation,
        ProductCategory $category,
        array $detailHeader,
        string $cellValue,
        ?string $imageUrl = null,
    ): Product {
        $vehicle = $generation->loadMissing('model.make');
        $source = $this->sourceFor($run);
        $productTitle = $this->productTitle($category, $vehicle, $detailHeader);
        $importKey = $this->importKey($vehicle, $category, $source);
        $slug = $this->stableSlug($vehicle, $category, $source, $productTitle);

        /** @var Product $product */
        $product = Product::query()->firstOrNew(['import_key' => $importKey]);
        $wasCreated = ! $product->exists;
        $product->fill([
            'product_category_id' => $category->getKey(),
            'title' => $productTitle,
            'slug' => $slug,
            'status' => ProductStatus::Active,
            'stock_status' => StockStatus::InStock,
            'price' => null,
            'import_source' => $source,
            'last_import_run_id' => (string) $run->getKey(),
        ]);
        $wasChanged = $product->isDirty();
        $product->save();

        if ($wasCreated) {
            $this->stats->increment($run, 'created_products');
        } elseif ($wasChanged) {
            $this->stats->increment($run, 'updated_products');
        }

        ProductVariant::query()->updateOrCreate(
            ['product_id' => $product->getKey(), 'is_default' => true],
            [
                'title' => 'Основной',
                'price' => 0,
                'stock_status' => StockStatus::InStock,
                'is_active' => true,
            ]
        );

        ProductFitment::query()->updateOrCreate(
            [
                'product_id' => $product->getKey(),
                'vehicle_generation_id' => $generation->getKey(),
            ],
            [
                'note' => null,
                'is_primary' => true,
            ]
        );

        $imageUrl ??= $this->isUrl($cellValue) ? trim($cellValue) : null;

        if ($imageUrl !== null && $this->shouldQueueProductImage($product, $imageUrl, $run)) {
            $this->statusService->imageQueued($run);
            DownloadProductImageJob::dispatch($product->getKey(), $imageUrl, $run->getKey())->onQueue('imports-images');
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

    /**
     * @param array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string}|null $detailHeader
     */
    public function productTitle(ProductCategory $category, VehicleGeneration $generation, ?array $detailHeader = null): string
    {
        $generation->loadMissing('model.make');

        $title = trim(implode(' ', array_filter([
            $this->detailTitleForProduct($category, $detailHeader),
            'для',
            $generation->model?->make?->title,
            $generation->model?->title,
            $generation->title,
            $generation->years_label,
            $generation->body,
        ], static fn (?string $part): bool => trim((string) $part) !== '')));

        return CatalogText::plain($title, 250);
    }

    public function importKey(VehicleGeneration $generation, ProductCategory $category, string $source = 'catalog'): string
    {
        $generation->loadMissing('model.make');

        return CatalogText::stableKey([
            CatalogText::normKey($source, 'catalog', 60) ?: 'catalog',
            $generation->model?->make?->norm_key,
            $generation->model?->norm_key,
            $generation->norm_key,
            ...$this->categoryImportKeySegments($category),
        ], ':', 240, 'catalog');
    }

    /** @return array<int, string> */
    private function categoryImportKeySegments(ProductCategory $category): array
    {
        $path = CatalogText::plain($category->full_slug ?: $category->slug ?: $category->title, 250);
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => trim($segment),
            explode('/', $path),
        ), static fn (string $segment): bool => $segment !== ''));

        return $segments !== [] ? $segments : [$category->title];
    }

    public function stableSlug(VehicleGeneration $generation, ProductCategory $category, string $source = 'catalog', ?string $productTitle = null): string
    {
        $normalizedSource = CatalogText::normKey($source, 'catalog', 60) ?: 'catalog';
        $titleSlug = CatalogText::slug($productTitle ?: $this->productTitle($category, $generation), 'product', 120);
        $identity = Str::after($this->importKey($generation, $category, $source), $normalizedSource.':');
        $base = $normalizedSource === 'catalog' ? $titleSlug : $normalizedSource.'-'.$titleSlug;

        return CatalogText::limitStable($base.'-'.substr(sha1($identity), 0, 8), 150);
    }


    /**
     * @param array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string}|null $detailHeader
     */
    private function detailTitleForProduct(ProductCategory $category, ?array $detailHeader = null): string
    {
        $fullDetailTitle = CatalogText::plain($detailHeader['full_detail_title'] ?? null, 250);

        if ($fullDetailTitle !== '') {
            return $fullDetailTitle;
        }

        $parentTitle = CatalogText::plain($detailHeader['parent_title'] ?? $detailHeader['group'] ?? null, 250);
        $detailTitle = CatalogText::plain($detailHeader['detail_title'] ?? $detailHeader['category_title'] ?? $detailHeader['title'] ?? null, 250);

        if ($parentTitle !== '' && $detailTitle !== '' && $parentTitle !== $detailTitle) {
            return CatalogText::plain($parentTitle.' '.$this->lowerFirst($detailTitle), 250);
        }

        return CatalogText::plain($detailTitle !== '' ? $detailTitle : $category->title, 250);
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
