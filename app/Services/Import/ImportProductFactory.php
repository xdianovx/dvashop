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
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\ImportRunStats;
use App\Services\ImportStatusService;
use App\Support\CatalogText;

class ImportProductFactory
{
    public function __construct(
        private readonly ImportRunStats $stats,
        private readonly ImportStatusService $statusService,
    ) {}

    /**
     * @param array{index:int, group:string|null, title:string, category_title:string} $detailHeader
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
        $productTitle = $this->productTitle($category, $vehicle);
        $importKey = $this->importKey($vehicle, $category, $source);
        $slug = $this->stableSlug($vehicle, $category, $source);

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

        if ($imageUrl !== null) {
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

    public function productTitle(ProductCategory $category, VehicleGeneration $generation): string
    {
        $generation->loadMissing('model.make');

        return trim(implode(' ', array_filter([
            $category->title,
            'для',
            $generation->model?->make?->title,
            $generation->model?->title,
            $generation->title,
            $generation->years_label,
            $generation->body,
        ], static fn (?string $part): bool => trim((string) $part) !== '')));
    }

    public function importKey(VehicleGeneration $generation, ProductCategory $category, string $source = 'catalog'): string
    {
        $generation->loadMissing('model.make');

        return CatalogText::stableKey([
            CatalogText::normKey($source, 'catalog', 60) ?: 'catalog',
            $generation->model?->make?->norm_key,
            $generation->model?->norm_key,
            $generation->norm_key,
            $category->full_slug,
        ], ':', 240, 'catalog');
    }

    public function stableSlug(VehicleGeneration $generation, ProductCategory $category, string $source = 'catalog'): string
    {
        $normalizedSource = CatalogText::normKey($source, 'catalog', 60) ?: 'catalog';
        $sourcePrefix = $normalizedSource.':';
        $base = Str::after($this->importKey($generation, $category, $source), $sourcePrefix);

        return CatalogText::slug($normalizedSource === 'catalog' ? $base : $normalizedSource.'-'.$base, 'product', 150);
    }

    public function isUrl(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_URL) !== false;
    }

    private function sourceFor(ImportRun $run): string
    {
        return CatalogText::normKey($run->type ?: 'catalog', 'catalog', 60) ?: 'catalog';
    }
}
