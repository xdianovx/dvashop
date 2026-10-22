<?php

namespace App\Services\Import;

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogImportDatabaseGuards;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\Catalog\CatalogPublicationPolicy;
use App\Services\ImportLogger;
use App\Services\Search\CatalogSearchPublication;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CatalogImportSnapshot
{
    public function prepare(ImportRun $run, array $prepared, array $headers, SpreadsheetReader $reader, ?array $fullPlan = null): int
    {
        if ($run->import_mode === 'safe_partial') {
            throw new RuntimeException('safe_partial не использует глобальный снимок публикации.');
        }
        $rows = (function () use ($prepared, $reader): iterable {
            for ($offset = 0; $offset < count($prepared['offsets']); $offset += 200) {
                yield from $reader->readPreparedChunk($prepared, $offset, 200);
            }
        })();
        $calculation = $fullPlan !== null ? ['products' => $fullPlan['snapshot_products'], 'duplicate_positive_cells' => 0, 'duplicate_vehicle_rows' => 0]
            : app(CatalogImportExpectedProducts::class)->collect($rows, $headers, $run->type ?: 'catalog');
        if ($fullPlan !== null && collect($fullPlan['full_scope']['archives'])->contains(fn ($a) => app(CatalogImportProtections::class)->protects($a['product_id']))
            && ! app(CatalogImportDatabaseGuards::class)->fullArchiveReady()) {
            throw new RuntimeException('Сначала разверните миграцию ограниченной архивации полного импорта.');
        }
        if ($calculation['duplicate_positive_cells'] > 0 || $calculation['duplicate_vehicle_rows'] > 0) {
            app(ImportLogger::class)->info($run, 'Повторные отметки наличия объединены по правилу OR', [
                'duplicate_vehicle_rows' => $calculation['duplicate_vehicle_rows'],
                'duplicate_positive_cells' => $calculation['duplicate_positive_cells'],
            ]);
        }
        $readonly = $fullPlan['full_scope']['readonly_present'] ?? [];
        $expected = array_map(static fn (array $product): array => [
            'import_run_id' => $run->id, 'import_key' => $product['import_key'], 'price' => null,
            'product_id' => in_array($product['product_id'] ?? null, $readonly, true) ? $product['product_id'] : null,
        ], $calculation['products']);
        DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->delete();
        foreach (array_chunk(array_values($expected), 500) as $items) {
            DB::table('catalog_import_snapshot_items')->insert($items);
        }

        return count($expected);
    }

    public function record(ImportRun $run, Product $product): void
    {
        $count = DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)
            ->where('import_key', $product->import_key)->update(['product_id' => $product->id]);
        if ($count !== 1 && ! DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id)->where('import_key', $product->import_key)->where('product_id', $product->id)->exists()) {
            throw new RuntimeException('Созданный импортный ключ отсутствует в проверенном снимке: '.$product->import_key);
        }
    }

    /** Publish assortment atomically. A failed or interrupted run never publishes its snapshot. */
    public function finalize(ImportRun $run, callable $archive): int
    {
        if ($run->import_mode === 'safe_partial') {
            throw new RuntimeException('Глобальная финализация safe_partial запрещена.');
        }

        return DB::transaction(function () use ($run, $archive): int {
            app(CatalogPriceGuard::class)->assertWritable();
            $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status?->isRowsRunning() || $locked->errors_count > 0 || $locked->processed_rows !== $locked->total_rows) {
                throw new RuntimeException('Финальная сверка запрещена: импорт не обработан полностью и успешно.');
            }
            $items = DB::table('catalog_import_snapshot_items')->where('import_run_id', $run->id);
            if ($items->count() !== ($run->prepared_rows['expected_products'] ?? 0) || (clone $items)->whereNull('product_id')->exists()) {
                throw new RuntimeException('Обработанные товары не соответствуют ожидаемому снимку.');
            }
            $source = CatalogText::normKey($run->type ?: 'catalog', 'catalog', 60);
            if ($run->import_mode === 'full') {
                $this->validateFullScope($locked);
            }
            Product::where('import_source', $source)->whereIn('id', (clone $items)->select('product_id'))
                ->when($run->import_mode === 'full', fn ($q) => $q->whereNotIn('id', array_keys($locked->prepared_rows['full_scope']['preserve'])))
                ->whereNotIn('id', app(CatalogImportProtections::class)->ids())
                ->where(fn ($q) => $q->whereNull('publication_override')->orWhere('publication_override', 'force_active'))
                ->update(['status' => ProductStatus::Active->value]);
            VehicleGeneration::where('last_import_run_id', $run->id)->update(['is_active' => true]);
            $archived = $archive();
            if (app(CatalogConfigurations::class)->ready()) {
                app(CatalogPublicationPolicy::class)->publishSnapshot($run->id, $source);
            }
            // Commit the authority marker with the status changes, not in a later job.
            app(CatalogSearchPublication::class)->record($locked);
            $locked->forceFill(['status' => $locked->hasPendingImages() ? ImportRunStatus::ProcessingImages : ImportRunStatus::Done,
                'finished_at' => $locked->hasPendingImages() ? null : now()])->save();

            return $archived;
        });
    }

    public function validateFullScope(ImportRun $run): void
    {
        $scope = $run->prepared_rows['full_scope'] ?? null;
        if (! is_array($scope) || ($scope['version'] ?? null) !== 1
            || ! hash_equals(CatalogImportAssortmentPolicy::seal($run->id, $scope), $scope['seal'] ?? '')) {
            throw new RuntimeException('Нет полного проверенного плана с исключениями. Загрузите и подтвердите файлы заново.');
        }
        foreach ([$run->stored_path => $scope['excel_sha256'], $run->configuration_path => $scope['csv_sha256']] as $path => $hash) {
            $file = Storage::disk('local')->path($path);
            if (! is_file($file) || ! hash_equals($hash, (string) hash_file('sha256', $file))) {
                throw new RuntimeException('Файлы полного импорта изменились.');
            }
        }
        if (! hash_equals($scope['matrix_version'], app(CatalogImportProtections::class)->matrixFingerprint())) {
            throw new RuntimeException('Матрица изменилась после начала полного импорта.');
        }
    }
}
