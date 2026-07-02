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
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Support\CatalogText;
use Illuminate\Support\Str;

class ImportProductFactory
{
    /**
     * @param array{index:int, group:string|null, title:string, category_title:string} $detailHeader
     */
    public function createOrUpdateFromCell(
        ImportRun $run,
        VehicleGeneration $generation,
        ProductCategory $category,
        array $detailHeader,
        string $cellValue,
    ): Product {
        $vehicle = $generation->loadMissing('model.make');
        $productTitle = $this->productTitle($category, $vehicle);
        $importKey = $this->importKey($vehicle, $category);
        $slug = $this->stableSlug($vehicle, $category);

        $product = Product::query()->updateOrCreate(
            ['import_key' => $importKey],
            [
                'product_category_id' => $category->getKey(),
                'title' => $productTitle,
                'slug' => $slug,
                'status' => ProductStatus::Active,
                'stock_status' => StockStatus::InStock,
                'price' => null,
                'last_import_run_id' => (string) $run->getKey(),
            ]
        );

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

        if ($this->isUrl($cellValue)) {
            DownloadProductImageJob::dispatch($product->getKey(), $cellValue, $run->getKey())->onQueue('imports-images');
        }

        return $product->refresh();
    }

    public function archiveMissingProducts(ImportRun $run): int
    {
        if ($run->total_rows <= 0 || $run->status !== ImportRunStatus::Running) {
            return 0;
        }

        return Product::query()
            ->whereNotNull('import_key')
            ->where(function ($query) use ($run): void {
                $query
                    ->whereNull('last_import_run_id')
                    ->orWhere('last_import_run_id', '!=', (string) $run->getKey());
            })
            ->where('status', '!=', ProductStatus::Archived->value)
            ->update(['status' => ProductStatus::Archived->value]);
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

    public function importKey(VehicleGeneration $generation, ProductCategory $category): string
    {
        $generation->loadMissing('model.make');

        return implode(':', [
            'catalog',
            $generation->model?->make?->norm_key,
            $generation->model?->norm_key,
            $generation->norm_key,
            $category->full_slug,
        ]);
    }

    public function stableSlug(VehicleGeneration $generation, ProductCategory $category): string
    {
        return CatalogText::slug(Str::after($this->importKey($generation, $category), 'catalog:'));
    }

    public function isUrl(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_URL) !== false;
    }
}
