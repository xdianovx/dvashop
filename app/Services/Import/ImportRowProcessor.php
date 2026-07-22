<?php

namespace App\Services\Import;

use App\Enums\ImportLogLevel;
use App\Jobs\DownloadVehicleGenerationImageJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\ImportLogger;
use App\Services\ImportRunStats;
use App\Services\ImportStatusService;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportRowProcessor
{
    public const DETAIL_START_COLUMN = 6;

    /** @var array<int, PartType> */
    private array $partTypeCache = [];

    /** @var array<int, ProductCategory> */
    private array $storeCategoryCache = [];

    /** @var array<string, bool> */
    private array $resolutionWarnings = [];

    public function __construct(
        private readonly ImportLogger $logger,
        private readonly ImportProductFactory $products,
        private readonly ImportPartTypeResolver $partTypes,
        private readonly ImportRunStats $stats,
        private readonly ImportStatusService $statusService,
    ) {}

    /**
     * @param array<int, array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string, category_id?:int, category_full_slug?:string, category_full_path?:string}> $detailColumns
     * @return array<int, array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string, part_type_id:int, part_type_full_slug:string, part_type_full_title:string, product_category_id:int, product_category_full_slug:string, product_category_full_path:string, part_type_used_fallback:bool}>
     */
    public function prepareDetailColumns(ImportRun $run, array $detailColumns): array
    {
        return DB::transaction(function () use ($run, $detailColumns): array {
            $prepared = [];

            foreach ($detailColumns as $columnIndex => $detailHeader) {
                $resolution = $this->partTypes->resolve($run, $detailHeader, (int) $columnIndex);

                unset(
                    $detailHeader['category_id'],
                    $detailHeader['category_full_slug'],
                    $detailHeader['category_full_path'],
                );

                $detailHeader['part_type_id'] = $resolution->partType->getKey();
                $detailHeader['part_type_full_slug'] = $resolution->partType->full_slug;
                $detailHeader['part_type_full_title'] = $resolution->partType->full_title;
                $detailHeader['product_category_id'] = $resolution->productCategory->getKey();
                $detailHeader['product_category_full_slug'] = $resolution->productCategory->full_slug;
                $detailHeader['product_category_full_path'] = $resolution->productCategory->full_title;
                $detailHeader['part_type_used_fallback'] = $resolution->usedFallback;
                $prepared[(int) $columnIndex] = $detailHeader;

                $this->partTypeCache[(int) $resolution->partType->getKey()] = $resolution->partType;
                $this->storeCategoryCache[(int) $resolution->productCategory->getKey()] = $resolution->productCategory;
            }

            return $prepared;
        });
    }

    /**
     * @param array<int, mixed> $row
     * @param array<int, array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string, part_type_id?:int, product_category_id?:int, part_type_used_fallback?:bool, category_id?:int}> $detailColumns
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

            $resolution = $this->detailResolution(
                run: $run,
                detailHeader: $detailHeader,
                rowNumber: $rowNumber,
                columnIndex: (int) $columnIndex,
            );

            if (! $availableCell['is_standard']) {
                $this->logger->warning($run, 'Нестандартное значение товарной ячейки принято как наличие товара', [
                    'row' => $rowNumber,
                    'column' => $this->columnName((int) $columnIndex),
                    'column_index' => (int) $columnIndex,
                    'value' => $cellValue,
                    'part_type' => $resolution->partType->full_slug,
                    'store_category' => $resolution->productCategory->full_slug,
                ]);
            }

            $this->products->createOrUpdateFromCell(
                run: $run,
                generation: $generation,
                partType: $resolution->partType,
                storeCategory: $resolution->productCategory,
                detailHeader: $detailHeader,
                cellValue: $cellValue,
                imageUrl: $availableCell['image_url'],
            );
        }
    }

    /**
     * @param array{parent_title?:string|null,group?:string|null,detail_title?:string|null,title?:string|null,full_detail_title?:string|null,category_title?:string|null,part_type_id?:int,product_category_id?:int,part_type_used_fallback?:bool,category_id?:int} $detailHeader
     */
    private function detailResolution(
        ImportRun $run,
        array $detailHeader,
        int $rowNumber,
        int $columnIndex,
    ): ImportPartTypeResolution {
        $partTypeId = (int) ($detailHeader['part_type_id'] ?? 0);
        $storeCategoryId = (int) ($detailHeader['product_category_id'] ?? 0);

        if ($partTypeId > 0 && $storeCategoryId > 0) {
            $partType = $this->partTypeCache[$partTypeId] ?? PartType::query()->find($partTypeId);
            $storeCategory = $this->storeCategoryCache[$storeCategoryId] ?? ProductCategory::query()->find($storeCategoryId);

            if ($partType instanceof PartType && $storeCategory instanceof ProductCategory) {
                $this->partTypeCache[$partTypeId] = $partType;
                $this->storeCategoryCache[$storeCategoryId] = $storeCategory;

                return new ImportPartTypeResolution(
                    partType: $partType,
                    productCategory: $storeCategory,
                    usedFallback: (bool) ($detailHeader['part_type_used_fallback'] ?? false),
                    wasCreated: false,
                    wasRestored: false,
                );
            }

            $this->warnResolutionOnce(
                run: $run,
                key: 'reresolve:'.$columnIndex,
                message: 'Подготовленные данные типа детали устарели, выполнено повторное разрешение',
                context: [
                    'row' => $rowNumber,
                    'column' => $this->columnName($columnIndex),
                    'column_index' => $columnIndex,
                    'part_type_id' => $partTypeId,
                    'product_category_id' => $storeCategoryId,
                ],
            );
        } elseif (! empty($detailHeader['category_id'])) {
            $this->warnResolutionOnce(
                run: $run,
                key: 'legacy-detail-columns',
                message: 'Устаревший формат detail_columns разрешён через PartType; category_id проигнорирован',
                context: [
                    'row' => $rowNumber,
                    'column' => $this->columnName($columnIndex),
                    'column_index' => $columnIndex,
                    'legacy_category_id' => (int) $detailHeader['category_id'],
                ],
            );
        } elseif ($partTypeId > 0 || $storeCategoryId > 0) {
            $this->warnResolutionOnce(
                run: $run,
                key: 'incomplete-detail-columns:'.$columnIndex,
                message: 'Неполные данные типа детали в detail_columns разрешены повторно',
                context: [
                    'row' => $rowNumber,
                    'column' => $this->columnName($columnIndex),
                    'column_index' => $columnIndex,
                    'part_type_id' => $partTypeId ?: null,
                    'product_category_id' => $storeCategoryId ?: null,
                ],
            );
        }

        $resolution = $this->partTypes->resolve($run, $detailHeader, $columnIndex);
        $this->partTypeCache[(int) $resolution->partType->getKey()] = $resolution->partType;
        $this->storeCategoryCache[(int) $resolution->productCategory->getKey()] = $resolution->productCategory;

        return $resolution;
    }

    private function warnResolutionOnce(ImportRun $run, string $key, string $message, array $context): void
    {
        $cacheKey = $run->getKey().':'.$key;

        if (isset($this->resolutionWarnings[$cacheKey])) {
            return;
        }

        $alreadyLogged = ImportLog::query()
            ->where('import_run_id', $run->getKey())
            ->where('level', ImportLogLevel::Warning->value)
            ->where('message', $message)
            ->exists();

        $this->resolutionWarnings[$cacheKey] = true;

        if (! $alreadyLogged) {
            $this->logger->warning($run, $message, $context);
        }
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
