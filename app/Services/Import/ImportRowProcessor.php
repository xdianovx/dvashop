<?php

namespace App\Services\Import;

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

        $generation = $this->vehicleGeneration($makeTitle, $modelTitle, $generationTitle, $years, $body);

        foreach ($detailColumns as $columnIndex => $detailHeader) {
            $cellValue = $this->cell($row[$columnIndex] ?? null);

            if (! $this->isAvailableCell($cellValue)) {
                continue;
            }

            $category = $this->productCategory($detailHeader);
            $this->products->createOrUpdateFromCell($run, $generation, $category, $detailHeader, $cellValue);
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

    public function vehicleGeneration(string $makeTitle, string $modelTitle, string $generationTitle, ?string $years = null, ?string $body = null): VehicleGeneration
    {
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

        return VehicleGeneration::query()->updateOrCreate(
            [
                'vehicle_model_id' => $model->getKey(),
                'norm_key' => $generationNormKey,
            ],
            [
                'title' => $generationTitle,
                'slug' => $generationNormKey,
                'years_label' => $years ?: null,
                'body' => $body ?: null,
                'is_active' => true,
            ]
        );
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

    private function isAvailableCell(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return $normalized !== ''
            && ! in_array($normalized, ['0', 'нет', 'no', 'false', '-'], true);
    }

    private function cell(mixed $value): string
    {
        return trim((string) $value);
    }
}
