<?php

namespace App\Services\Import;

use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportRun;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\ImportLogger;
use App\Support\CatalogText;

class ImportRowProcessor
{
    public const DETAIL_START_COLUMN = 6;

    public function __construct(
        private readonly ImportLogger $logger,
        private readonly ImportProductFactory $products,
    ) {}

    /**
     * @param array<int, mixed> $row
     * @param array<int, array{index:int, group:string|null, title:string, category_title:string}> $detailColumns
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

            $category = $this->productCategory($detailHeader);

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
     * @param array{index:int, group:string|null, title:string, category_title:string} $detailHeader
     */
    public function productCategory(array $detailHeader): ProductCategory
    {
        $groupTitle = $this->cell($detailHeader['group'] ?? null);
        $categoryTitle = $this->cell($detailHeader['category_title'] ?? $detailHeader['title'] ?? null);

        if ($categoryTitle === '' && $groupTitle !== '') {
            $categoryTitle = $groupTitle;
        }

        if ($groupTitle === '' || $groupTitle === $categoryTitle) {
            return $this->firstOrRestoreCategory(null, $categoryTitle);
        }

        $parent = $this->firstOrRestoreCategory(null, $groupTitle);

        return $this->firstOrRestoreCategory($parent, $categoryTitle);
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
        $make = VehicleMake::query()->updateOrCreate(
            ['norm_key' => CatalogText::normKey($makeTitle)],
            [
                'title' => $makeTitle,
                'slug' => CatalogText::slug($makeTitle),
                'is_active' => true,
            ]
        );

        $model = VehicleModel::query()->updateOrCreate(
            [
                'vehicle_make_id' => $make->getKey(),
                'norm_key' => CatalogText::normKey($modelTitle),
            ],
            [
                'title' => $modelTitle,
                'slug' => CatalogText::slug($modelTitle),
                'is_active' => true,
            ]
        );

        $generationNormKey = CatalogText::normKey(trim($generationTitle.' '.$years.' '.$body));
        $imageUrl = $this->isUrl($this->cell($sourceImageUrl)) ? $this->cell($sourceImageUrl) : null;

        $generation = VehicleGeneration::query()->updateOrCreate(
            [
                'vehicle_model_id' => $model->getKey(),
                'norm_key' => $generationNormKey,
            ],
            [
                'title' => $generationTitle,
                'slug' => $generationNormKey,
                'years_label' => $years ?: null,
                'body' => $body ?: null,
                'image_source_url' => $imageUrl,
                'is_active' => true,
            ]
        );

        if ($imageUrl !== null && ($generation->wasRecentlyCreated || $generation->wasChanged('image_source_url') || $generation->image === null)) {
            DownloadVehicleGenerationImageJob::dispatch($generation->getKey(), $imageUrl, $run?->getKey())->onQueue('imports-images');
        }

        return $generation;
    }

    private function firstOrRestoreCategory(?ProductCategory $parent, string $title): ProductCategory
    {
        $slug = CatalogText::slug($title);

        /** @var ProductCategory $category */
        $category = ProductCategory::withTrashed()->firstOrNew([
            'parent_id' => $parent?->getKey(),
            'slug' => $slug,
        ]);

        $category->fill([
            'title' => $title,
            'is_active' => true,
        ]);

        if ($category->trashed()) {
            $category->restore();
        }

        $category->save();

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
