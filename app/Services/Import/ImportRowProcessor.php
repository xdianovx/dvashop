<?php

namespace App\Services\Import;

use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportRun;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\ImportLogger;
use App\Services\ImportRunStats;
use App\Services\ImportStatusService;
use App\Support\CatalogText;

class ImportRowProcessor
{
    public const DETAIL_START_COLUMN = 6;

    public function __construct(
        private readonly ImportLogger $logger,
        private readonly ImportProductFactory $products,
        private readonly ImportRunStats $stats,
        private readonly ImportStatusService $statusService,
    ) {}

    /**
     * @param array<int, array{index:int, group:string|null, title:string, category_title:string, category_id?:int}> $detailColumns
     * @return array<int, array{index:int, group:string|null, title:string, category_title:string, category_id:int}>
     */
    public function prepareDetailColumns(ImportRun $run, array $detailColumns): array
    {
        $prepared = [];

        foreach ($detailColumns as $columnIndex => $detailHeader) {
            $category = $this->productCategory($detailHeader, $run);
            $detailHeader['category_id'] = $category->getKey();
            $prepared[(int) $columnIndex] = $detailHeader;
        }

        return $prepared;
    }

    /**
     * @param array<int, mixed> $row
     * @param array<int, array{index:int, group:string|null, title:string, category_title:string, category_id?:int}> $detailColumns
     */
    public function process(ImportRun $run, array $row, array $detailColumns, int $rowNumber): void
    {
        $vehicleImageUrl = $this->cell($row[0] ?? null);
        $makeTitle = $this->cell($row[1] ?? null);
        $modelTitle = $this->cell($row[2] ?? null);
        $generationTitle = $this->cell($row[3] ?? null);
        $years = $this->cell($row[4] ?? null);
        $body = $this->cell($row[5] ?? null);

        if ($makeTitle === '' || $modelTitle === '' || $generationTitle === '') {
            $this->logger->warning($run, 'Строка пропущена: не заполнены марка, модель или поколение', [
                'row' => $rowNumber,
                'make' => $makeTitle,
                'model' => $modelTitle,
                'generation' => $generationTitle,
            ]);

            return;
        }

        $generation = $this->vehicleGeneration($makeTitle, $modelTitle, $generationTitle, $years, $body, $vehicleImageUrl, $run);

        foreach ($detailColumns as $columnIndex => $detailHeader) {
            $cellValue = $this->cell($row[$columnIndex] ?? null);
            $availableCell = $this->availableCell($cellValue);

            if ($availableCell === null) {
                continue;
            }

            $category = $this->productCategory($detailHeader, $run);

            if (! $availableCell['is_standard']) {
                $this->logger->warning($run, 'Нестандартное значение товарной ячейки принято как наличие товара', [
                    'row' => $rowNumber,
                    'column' => $this->columnName((int) $columnIndex),
                    'column_index' => (int) $columnIndex,
                    'value' => $cellValue,
                    'category' => $category->full_slug,
                ]);
            }

            $this->products->createOrUpdateFromCell(
                run: $run,
                generation: $generation,
                category: $category,
                detailHeader: $detailHeader,
                cellValue: $cellValue,
                imageUrl: $availableCell['image_url'],
            );
        }
    }

    /**
     * @param array{index:int, group:string|null, title:string, category_title:string, category_id?:int} $detailHeader
     */
    public function productCategory(array $detailHeader, ?ImportRun $run = null): ProductCategory
    {
        if (! empty($detailHeader['category_id'])) {
            $category = ProductCategory::query()->find($detailHeader['category_id']);

            if ($category instanceof ProductCategory) {
                return $category;
            }
        }

        $groupTitle = $this->cell($detailHeader['group'] ?? null);
        $categoryTitle = $this->cell($detailHeader['category_title'] ?? $detailHeader['title'] ?? null);

        if ($categoryTitle === '' && $groupTitle !== '') {
            $categoryTitle = $groupTitle;
        }

        if ($groupTitle === '' || $groupTitle === $categoryTitle) {
            return $this->firstOrRestoreCategory(null, $categoryTitle, $run);
        }

        $parent = $this->firstOrRestoreCategory(null, $groupTitle, $run);

        return $this->firstOrRestoreCategory($parent, $categoryTitle, $run);
    }

    public function vehicleGeneration(
        string $makeTitle,
        string $modelTitle,
        string $generationTitle,
        ?string $years = null,
        ?string $body = null,
        ?string $sourceImageUrl = null,
        ?ImportRun $run = null,
    ): VehicleGeneration {
        $make = $this->firstOrUpdateMake($makeTitle, $run);
        $model = $this->firstOrUpdateModel($make, $modelTitle, $run);
        $generationNormKey = CatalogText::normKey(trim($generationTitle.' '.$years.' '.$body), 'generation', 120);
        $imageUrl = $this->isUrl($this->cell($sourceImageUrl)) ? $this->cell($sourceImageUrl) : null;

        /** @var VehicleGeneration $generation */
        $generation = VehicleGeneration::query()->firstOrNew([
            'vehicle_model_id' => $model->getKey(),
            'norm_key' => $generationNormKey,
        ]);

        $wasCreated = ! $generation->exists;
        $generation->fill([
            'title' => CatalogText::plain($generationTitle, 250),
            'slug' => $generationNormKey,
            'years_label' => $years !== null && $years !== '' ? CatalogText::plain($years, 250) : null,
            'body' => $body !== null && $body !== '' ? CatalogText::plain($body, 250) : null,
            'image_source_url' => $imageUrl,
            'is_active' => true,
        ]);
        $wasChanged = $generation->isDirty();
        $generation->save();

        if ($run !== null) {
            if ($wasCreated) {
                $this->stats->increment($run, 'created_generations');
            } elseif ($wasChanged) {
                $this->stats->increment($run, 'updated_generations');
            }
        }

        if ($imageUrl !== null && ($wasCreated || $generation->wasChanged('image_source_url') || $generation->image === null)) {
            if ($run !== null) {
                $this->statusService->imageQueued($run);
            }

            DownloadVehicleGenerationImageJob::dispatch($generation->getKey(), $imageUrl, $run?->getKey())->onQueue('imports-images');
        }

        return $generation->refresh();
    }

    private function firstOrUpdateMake(string $title, ?ImportRun $run): VehicleMake
    {
        /** @var VehicleMake $make */
        $make = VehicleMake::query()->firstOrNew(['norm_key' => CatalogText::normKey($title, 'make', 100)]);
        $wasCreated = ! $make->exists;
        $make->fill([
            'title' => CatalogText::plain($title, 250),
            'slug' => CatalogText::slug($title, 'make', 100),
            'is_active' => true,
        ]);
        $wasChanged = $make->isDirty();
        $make->save();

        if ($run !== null) {
            if ($wasCreated) {
                $this->stats->increment($run, 'created_makes');
            } elseif ($wasChanged) {
                $this->stats->increment($run, 'updated_makes');
            }
        }

        return $make->refresh();
    }

    private function firstOrUpdateModel(VehicleMake $make, string $title, ?ImportRun $run): VehicleModel
    {
        /** @var VehicleModel $model */
        $model = VehicleModel::query()->firstOrNew([
            'vehicle_make_id' => $make->getKey(),
            'norm_key' => CatalogText::normKey($title, 'model', 100),
        ]);
        $wasCreated = ! $model->exists;
        $model->fill([
            'title' => CatalogText::plain($title, 250),
            'slug' => CatalogText::slug($title, 'model', 100),
            'is_active' => true,
        ]);
        $wasChanged = $model->isDirty();
        $model->save();

        if ($run !== null) {
            if ($wasCreated) {
                $this->stats->increment($run, 'created_models');
            } elseif ($wasChanged) {
                $this->stats->increment($run, 'updated_models');
            }
        }

        return $model->refresh();
    }

    private function firstOrRestoreCategory(?ProductCategory $parent, string $title, ?ImportRun $run = null): ProductCategory
    {
        $slug = CatalogText::slug($title, 'category', 80);

        /** @var ProductCategory $category */
        $category = ProductCategory::withTrashed()->firstOrNew([
            'parent_id' => $parent?->getKey(),
            'slug' => $slug,
        ]);

        $wasCreated = ! $category->exists;
        $wasTrashed = $category->exists && $category->trashed();

        $category->fill([
            'title' => CatalogText::plain($title, 250),
            'is_active' => true,
        ]);
        $wasChanged = $category->isDirty();

        if ($category->trashed()) {
            $category->restore();
        }

        $category->save();

        if ($run !== null) {
            if ($wasCreated) {
                $this->stats->increment($run, 'created_categories');
            } elseif ($wasChanged || $wasTrashed) {
                $this->stats->increment($run, 'updated_categories');
            }
        }

        return $category->refresh();
    }

    /** @return array{image_url:string|null, is_standard:bool}|null */
    private function availableCell(string $value): ?array
    {
        $normalized = mb_strtolower(str_replace(',', '.', trim($value)));

        if ($normalized === '' || $normalized === '-' || in_array($normalized, ['нет', 'no', 'false'], true) || preg_match('/^0(?:\.0+)?$/', $normalized) === 1) {
            return null;
        }

        if ($this->isUrl($value)) {
            return ['image_url' => trim($value), 'is_standard' => true];
        }

        if (in_array($normalized, ['да', 'yes', 'true'], true) || preg_match('/^1(?:\.0+)?$/', $normalized) === 1) {
            return ['image_url' => null, 'is_standard' => true];
        }

        return ['image_url' => null, 'is_standard' => false];
    }

    private function isUrl(string $value): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_URL) !== false;
    }

    private function columnName(int $zeroBasedIndex): string
    {
        $number = $zeroBasedIndex + 1;
        $name = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $number = intdiv($number - 1, 26);
        }

        return $name;
    }

    private function cell(mixed $value): string
    {
        return trim((string) $value);
    }
}
