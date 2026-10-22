<?php

namespace App\Services\Import;

use App\Enums\ImportLogLevel;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportContext;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Catalog\CatalogPriceGuard;
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
    use PreparesStructuralArchive;

    /** Existing cards keep their user data; the shared matrix synchronizer owns offers/prices. */
    public function applySafePartial(ImportRun $run, array $item): Product
    {
        app(CatalogSafePartialImport::class)->assertItemOwner($run);
        $expected = $item['expected'];
        if ($expected['product_id']) {
            $product = Product::whereKey($expected['product_id'])->firstOrFail();
            app(CatalogConfigurations::class)->synchronize($product, 'import:'.$run->id);
            $changes = ['last_import_run_id' => $run->id];
            if ($expected['action'] === 'reactivate' && ! in_array($product->publication_override, ['archived', 'draft'], true)) {
                $changes['status'] = ProductStatus::Active->value;
                $changes['import_present'] = true;
            }
            DB::table('products')->where('id', $product->id)->update($changes);
            $this->stats->increment($run, 'updated_products');

            return $product->refresh();
        }
        $headers = [];
        foreach ($run->detail_columns as $column => $header) {
            if (app(ImportPartTypeResolver::class)->structuralPath($header) === $expected['part_type_full_slug']) {
                $headers[$column] = $header;
            }
        }
        if (count($headers) !== 1) {
            throw new \RuntimeException('Не найдена единственная разрешённая колонка нового товара.');
        }
        $part = PartType::where('full_slug', $expected['part_type_full_slug'])->firstOrFail();
        $category = $part->productCategory()->firstOrFail();
        foreach ($headers as &$header) {
            $header += ['part_type_id' => $part->id, 'part_type_full_slug' => $part->full_slug,
                'product_category_id' => $category->id];
        }
        unset($header);
        $row = $item['row'];
        $row[0] = '';
        $row['_photo_urls'] = [];
        app(ImportRowProcessor::class)->process($run, $row, $headers, $row['_row_number']);

        return Product::where('import_key', $expected['import_key'])->firstOrFail();
    }

    public function __construct(
        private readonly ImportRunStats $stats,
        private readonly ImportStatusService $statusService,
        private readonly ProductGalleryService $gallery,
        private readonly ImportLogger $logger,
        private readonly CatalogImportPriceResolver $priceResolver,
        private readonly CatalogImportProductDefaults $productDefaults,
        private readonly CatalogImportVehiclePruner $vehiclePruner,
        private readonly CatalogImportIdentity $identity,
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
        ?array $identityRow = null,
    ): Product {
        if ($run->import_mode === 'safe_partial') {
            app(CatalogSafePartialImport::class)->assertItemOwner($run);
        }
        $vehicle = $generation->loadMissing('model.make');
        $source = $this->sourceFor($run);
        $productTitle = $this->productTitle($partType, $vehicle);
        $resolved = $identityRow !== null ? $this->identity->resolve($identityRow, $partType->full_slug, $source, $run) : null;
        if (($resolved['error'] ?? null) !== null) {
            throw new \RuntimeException($resolved['error']);
        }
        $importKey = $resolved['import_key'] ?? $this->importKey($vehicle, $partType, $source);
        $slug = $this->stableSlug($vehicle, $partType, $source, $productTitle);
        $referencePrice = $this->priceResolver->resolve($partType);

        if ($referencePrice === null) {
            $this->warnMissingReferencePriceOnce($run, $partType);
        }

        /** @var Product $product */
        $product = ($resolved['approved_identity'] ?? false)
            ? Product::query()->withTrashed()->find($resolved['product_id'])
            : Product::query()->withTrashed()->firstOrNew(['import_key' => $importKey]);
        if (! $product instanceof Product || $product->import_key !== $importKey
            || (($resolved['approved_identity'] ?? false) && $vehicle->id !== $resolved['generation_id'])) {
            throw new \RuntimeException('Утверждённый исторический товар или его автомобиль изменились; создание замены запрещено.');
        }
        $wasCreated = ! $product->exists;
        $initializeDefaults = $wasCreated && $source === 'catalog';
        $matrixTemplate = $source === 'catalog' ? $this->productDefaults->templateFor($partType) : null;

        $productAttributes = [
            'product_type' => ProductType::AutoPart,
            'part_type_id' => $partType->getKey(),
            'product_category_id' => $storeCategory->getKey(),
            'title' => $productTitle,
            'slug' => $product->exists ? $product->slug : $slug,
            'status' => in_array($product->publication_override, ['archived', 'draft'], true)
                ? ProductStatus::from($product->publication_override)
                : ($run->isSnapshotCatalogImport() ? ($product->exists && ! $product->trashed() ? $product->status : ProductStatus::Archived) : ProductStatus::Active),
            'import_source' => $source,
            'last_import_run_id' => (string) $run->getKey(),
        ];

        if ($wasCreated) {
            $productAttributes['stock_status'] = StockStatus::InStock;
            $productAttributes['price'] = $referencePrice['price'] ?? null;
            $productAttributes['price_mode'] = $referencePrice === null ? 'unknown' : 'inherited';
            $productAttributes['last_import_price'] = $referencePrice['price'] ?? null;
            $productAttributes['old_price'] = $referencePrice['old_price'] ?? null;
        }

        if ($initializeDefaults) {
            $productAttributes['product_option_template_id'] = $matrixTemplate?->getKey();
            $productAttributes['description'] = $this->productDefaults->description($partType, $vehicle);
        }

        [$product, $variant] = DB::transaction(function () use (
            $product,
            $productAttributes,
            $wasCreated,
            $initializeDefaults,
            $run,
            $referencePrice,
            $matrixTemplate,
            $partType,
            $storeCategory,
            $generation,
        ): array {
            if (! $wasCreated && $product->trashed()) {
                $product->restore();
            }

            $product->forceFill($productAttributes);
            $wasChanged = $product->isDirty();
            $product->save();

            if ($wasCreated) {
                $this->stats->increment($run, 'created_products');
            } elseif ($wasChanged) {
                $this->stats->increment($run, 'updated_products');
            }

            if ($run->type === 'catalog') {
                if ($initializeDefaults) {
                    $this->productDefaults->initialize($product, $matrixTemplate, (int) $run->getKey());
                } else {
                    app(CatalogConfigurations::class)->synchronize($product, 'import:'.$run->getKey());
                }
                // A manually excluded matrix may have no active default. Never create a technical offer.
                $variant = $product->defaultVariant()->first()
                    ?? $product->variants()->orderByDesc('is_active')->orderBy('id')->firstOrFail();
            } else {
                $variant = ProductVariant::query()->firstOrCreate([
                    'product_id' => $product->getKey(), 'is_default' => true,
                ], [
                    'title' => 'Основной', 'price' => $referencePrice['price'] ?? 0,
                    'price_mode' => $referencePrice === null ? 'unknown' : 'inherited',
                    'old_price' => $referencePrice['old_price'] ?? null,
                    'stock_status' => StockStatus::InStock, 'is_active' => true,
                ]);
            }

            $product->setRelation('partType', $partType);
            $product->setRelation('category', $storeCategory);
            $product->setRelation('defaultVariant', $variant);

            if ($run->isSnapshotCatalogImport()) {
                // Staged generations are deliberately inactive. The snapshot validates
                // their identity; database foreign keys protect this internal relation.
                ProductFitment::query()->insertOrIgnore([
                    'product_id' => $product->id, 'vehicle_generation_id' => $generation->id,
                    'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
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
            }

            return [$product, $variant];
        });

        if ($run->import_mode === 'safe_partial') {
            return $product->refresh();
        }
        $imageUrl = $source === 'catalog' ? null : ($imageUrl ?? ($this->isUrl($cellValue) ? trim($cellValue) : null));

        if ($imageUrl !== null) {
            $this->promoteExistingImportImageIfPresent($product, $imageUrl);

            if ($this->shouldQueueProductImage($product, $imageUrl, $run)) {
                app(ImportImageTasks::class)->enqueue($run, 'product', $product->id, $imageUrl);
            }
        } elseif ($this->isPositiveAvailabilityCell($cellValue)) {
            $this->warnImportImageUrlDisappearedOnce($product, $run, $partType, $storeCategory);
            $this->attachDefaultImage($product, $partType, $run);
        }

        if ($run->isSnapshotCatalogImport()) {
            app(CatalogImportSnapshot::class)->record($run, $product);
        }

        return $product->refresh();
    }

    public function archiveMissingProducts(ImportRun $run): int
    {
        if ($run->import_mode === 'safe_partial') {
            return 0;
        }
        if ($run->total_rows <= 0 || ! $run->status?->isRowsRunning() || $run->errors_count > 0) {
            return 0;
        }

        if ($run->import_mode === 'full') {
            return $this->archiveProvedMissing($run);
        }

        return DB::transaction(function () use ($run): int {
            $archived = Product::query()
                ->where('import_source', $this->sourceFor($run))
                ->whereNotNull('import_key')
                ->whereNotIn('id', app(CatalogImportProtections::class)->ids())
                ->where(function ($query) use ($run): void {
                    if ($run->isSnapshotCatalogImport()) {
                        $query->whereNotIn('import_key', DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->select('import_key'));
                    } else {
                        $query->whereNull('last_import_run_id')->orWhere('last_import_run_id', '!=', (string) $run->getKey());
                    }
                })
                ->where('status', '!=', ProductStatus::Archived->value)
                ->where(fn ($q) => $q->whereNull('publication_override')->orWhere('publication_override', 'force_active'))
                ->update(['status' => ProductStatus::Archived->value]);

            $this->stats->increment($run, 'archived_products', $archived);
            $this->vehiclePruner->prune($run);

            return $archived;
        });
    }

    private function archiveProvedMissing(ImportRun $run): int
    {
        return DB::transaction(function () use ($run): int {
            app(CatalogPriceGuard::class)->assertWritable();
            app(CatalogImportSnapshot::class)->validateFullScope($run);
            $scope = $run->prepared_rows['full_scope'];
            $file = Storage::disk('local')->path($run->stored_path);
            $current = app(CatalogImportProductPlan::class)->full($file, $run->type, materialized: true);
            $ids = array_keys($scope['archives']);
            $products = Product::withTrashed()->with(['fitments' => fn ($q) => $q->lockForUpdate()])
                ->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $present = DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->whereIn('product_id', $ids)->exists();
            if ($present || array_intersect($ids, array_keys($current['full_scope']['preserve']))) {
                throw new \RuntimeException('Архивация пересекается с присутствующей или неопределённой областью.');
            }
            foreach ($scope['archives'] as $id => $proof) {
                if (! isset($current['full_scope']['archives'][$id]) || ! isset($products[$id])
                    || ! hash_equals($proof['fingerprint'], CatalogImportAssortmentPolicy::fingerprint($products[$id]))) {
                    throw new \RuntimeException('Доказательство отсутствия товара #'.$id.' устарело.');
                }
                if (($proof['evidence']['code'] ?? null) === 'structural_generic_retired'
                    && ! $this->structuralEvidenceMatches($proof['evidence'], $current['full_scope']['archives'][$id]['evidence'])) {
                    throw new \RuntimeException('Доказательство отдельных структурных позиций #'.$id.' устарело.');
                }
            }
            $nonce = bin2hex(random_bytes(32));
            $prepared = $run->prepared_rows;
            $run->forceFill(['prepared_rows' => $prepared + ['full_archive_nonce' => $nonce]])->save();
            $archived = 0;
            try {
                CatalogImportContext::archive($run->id, $nonce, function () use ($scope, &$archived): void {
                    foreach ($scope['archives'] as $id => $proof) {
                        DB::table('products')->where('id', $id)->update(['status' => 'archived', 'import_present' => false, 'updated_at' => now()]);
                        $archived += $proof['status'] !== 'archived' ? 1 : 0;
                    }
                });
            } finally {
                $run->forceFill(['prepared_rows' => $prepared])->save();
            }
            $this->stats->increment($run, 'archived_products', $archived);
            $this->vehiclePruner->prune($run);

            return $archived;
        });
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

        return $this->identity->forGeneration($generation, implode('/', $this->partTypeImportKeySegments($partType)), $source);
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
        return CatalogImportCell::parse($value) !== null;
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
