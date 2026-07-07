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
use Illuminate\Support\Facades\Storage;

class ImportRowProcessor
{
    public const DETAIL_START_COLUMN = 6;

    /** @var array<int, ProductCategory> */
    private array $categoryCache = [];

    public function __construct(
        private readonly ImportLogger $logger,
        private readonly ImportProductFactory $products,
        private readonly ImportRunStats $stats,
        private readonly ImportStatusService $statusService,
    ) {}

    /**
     * @param array<int, array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string, category_id?:int, category_full_slug?:string, category_full_path?:string}> $detailColumns
     * @return array<int, array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string, category_id:int, category_full_slug:string, category_full_path:string}>
     */
    public function prepareDetailColumns(ImportRun $run, array $detailColumns): array
    {
        $prepared = [];

        foreach ($detailColumns as $columnIndex => $detailHeader) {
            $category = $this->productCategory($detailHeader, $run, true);
            if (! $category instanceof ProductCategory) {
                continue;
            }
            $detailHeader['category_id'] = $category->getKey();
            $detailHeader['category_full_slug'] = $category->full_slug;
            $detailHeader['category_full_path'] = $category->full_title;
            $prepared[(int) $columnIndex] = $detailHeader;
        }

        return $prepared;
    }

    /**
     * @param array<int, mixed> $row
     * @param array<int, array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string, category_id?:int, category_full_slug?:string, category_full_path?:string}> $detailColumns
     */
    public function process(ImportRun $run, array $row, array $detailColumns, int $rowNumber): void
    {
        $vehicleImageCell = $this->cell($row[0] ?? null);
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

        $generation = $this->vehicleGeneration($makeTitle, $modelTitle, $generationTitle, $years, $body, $vehicleImageCell, $run, $rowNumber);

        foreach ($detailColumns as $columnIndex => $detailHeader) {
            $cellValue = $this->cell($row[$columnIndex] ?? null);
            $availableCell = $this->availableCell($cellValue);

            if ($availableCell === null) {
                continue;
            }

            $category = $this->productCategory($detailHeader, $run, false, $rowNumber, (int) $columnIndex);

            if (! $category instanceof ProductCategory) {
                continue;
            }

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
     * @param array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string, category_id?:int, category_full_slug?:string, category_full_path?:string} $detailHeader
     */
    public function productCategory(array $detailHeader, ?ImportRun $run = null, bool $createIfMissing = true, ?int $rowNumber = null, ?int $columnIndex = null): ?ProductCategory
    {
        if (! empty($detailHeader['category_id'])) {
            $categoryId = (int) $detailHeader['category_id'];

            if (isset($this->categoryCache[$categoryId])) {
                return $this->categoryCache[$categoryId];
            }

            $category = ProductCategory::query()->find($categoryId);

            if ($category instanceof ProductCategory) {
                return $this->categoryCache[$categoryId] = $category;
            }

            if (! $createIfMissing) {
                if ($run !== null) {
                    $this->logger->warning($run, 'Категория товара из detail_columns не найдена, ячейка пропущена', [
                        'row' => $rowNumber,
                        'column' => $columnIndex === null ? null : $this->columnName($columnIndex),
                        'column_index' => $columnIndex,
                        'category_id' => $categoryId,
                    ]);
                }

                return null;
            }
        }

        $groupTitle = $this->cell($detailHeader['parent_title'] ?? $detailHeader['group'] ?? null);
        $categoryTitle = $this->cell($detailHeader['category_title'] ?? $detailHeader['detail_title'] ?? $detailHeader['title'] ?? null);

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
        ?int $rowNumber = null,
    ): VehicleGeneration {
        $make = $this->firstOrUpdateMake($makeTitle, $run);
        $model = $this->firstOrUpdateModel($make, $modelTitle, $run);
        $generationTitlePlain = CatalogText::plain($generationTitle, 250);
        $yearsPlain = $years !== null && $years !== '' ? CatalogText::plain($years, 250) : null;
        $bodyPlain = $body !== null && $body !== '' ? CatalogText::plain($body, 250) : null;
        $generationNormKey = CatalogText::normKey(trim($generationTitlePlain.' '.$yearsPlain.' '.$bodyPlain), 'generation', 120);
        $imageUrl = $this->vehicleImageUrl($sourceImageUrl, $run, $rowNumber);

        /** @var VehicleGeneration|null $generation */
        $generation = VehicleGeneration::query()
            ->where('vehicle_model_id', $model->getKey())
            ->where(function ($query) use ($generationNormKey): void {
                $query->where('norm_key', $generationNormKey)
                    ->orWhere('slug', $generationNormKey);
            })
            ->first();

        if (! $generation instanceof VehicleGeneration) {
            $generation = VehicleGeneration::query()
                ->where('vehicle_model_id', $model->getKey())
                ->where('title', $generationTitlePlain)
                ->where(function ($query) use ($yearsPlain): void {
                    $yearsPlain === null
                        ? $query->whereNull('years_label')
                        : $query->where('years_label', $yearsPlain);
                })
                ->where(function ($query) use ($bodyPlain): void {
                    $bodyPlain === null
                        ? $query->whereNull('body')
                        : $query->where('body', $bodyPlain);
                })
                ->first();
        }

        if (! $generation instanceof VehicleGeneration) {
            $generation = new VehicleGeneration([
                'vehicle_model_id' => $model->getKey(),
            ]);
        }

        $wasCreated = ! $generation->exists;
        $previousImageSourceUrl = $generation->exists ? $generation->image_source_url : null;
        $previousImagePath = $generation->exists ? $generation->image : null;
        $hasManualVehicleImage = $imageUrl !== null
            && $previousImageSourceUrl === null
            && is_string($previousImagePath)
            && $previousImagePath !== '';
        $shouldQueueImage = false;

        $generationAttributes = [
            'title' => $generationTitlePlain,
            'slug' => $generationNormKey,
            'norm_key' => $generationNormKey,
            'years_label' => $yearsPlain,
            'body' => $bodyPlain,
            'is_active' => true,
        ];

        if ($imageUrl !== null) {
            if ($hasManualVehicleImage) {
                $this->warnManualVehicleImageProtected($generation, $imageUrl, $run, $rowNumber);
                $imageUrl = null;
            } else {
                $generationAttributes['image_source_url'] = $imageUrl;
                $shouldQueueImage = $this->shouldQueueVehicleImage($generation, $imageUrl, $run, $previousImageSourceUrl, $previousImagePath);
            }
        }

        $generation->fill($generationAttributes);
        $wasChanged = $generation->isDirty();
        $generation->save();

        if ($run !== null) {
            if ($wasCreated) {
                $this->stats->increment($run, 'created_generations');
            } elseif ($wasChanged) {
                $this->stats->increment($run, 'updated_generations');
            }
        }

        if ($imageUrl !== null && $shouldQueueImage) {
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


    /** @var array<string, bool> */
    private array $queuedVehicleImages = [];

    /** @var array<string, bool> */
    private array $manualVehicleImageWarnings = [];

    private function vehicleImageUrl(?string $value, ?ImportRun $run, ?int $rowNumber): ?string
    {
        $value = $this->cell($value);

        if ($value === '') {
            return null;
        }

        if ($this->isUrl($value)) {
            return $value;
        }

        $availability = $this->availableCell($value);

        if ($availability !== null && $availability['is_standard'] && $availability['image_url'] === null) {
            return null;
        }

        if ($run !== null) {
            $this->logger->warning($run, 'Некорректное значение в колонке фото автомобиля пропущено', [
                'row' => $rowNumber,
                'column' => 'A',
                'value' => $value,
            ]);
        }

        return null;
    }

    private function shouldQueueVehicleImage(
        VehicleGeneration $generation,
        string $url,
        ?ImportRun $run,
        ?string $previousImageSourceUrl = null,
        ?string $previousImagePath = null,
    ): bool {
        $key = ($run?->getKey() ?? 0).':'.($generation->getKey() ?: 'new').':'.sha1($url);

        if (isset($this->queuedVehicleImages[$key])) {
            return false;
        }

        $path = $previousImagePath ?? $generation->image;

        if ($previousImageSourceUrl === $url && is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            return false;
        }

        $this->queuedVehicleImages[$key] = true;

        return true;
    }

    private function warnManualVehicleImageProtected(VehicleGeneration $generation, string $url, ?ImportRun $run, ?int $rowNumber): void
    {
        if ($run === null) {
            return;
        }

        $key = $run->getKey().':'.($generation->getKey() ?: sha1($url)).':manual-vehicle-image';

        if (isset($this->manualVehicleImageWarnings[$key])) {
            return;
        }

        $this->manualVehicleImageWarnings[$key] = true;

        $this->logger->warning($run, 'Ручное фото поколения авто не перезаписано импортом', [
            'row' => $rowNumber,
            'column' => 'A',
            'vehicle_generation_id' => $generation->getKey(),
            'url' => $url,
        ]);
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
        return CatalogText::plain($value, 1000);
    }
}
