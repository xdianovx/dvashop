<?php

namespace App\Services\Import;

use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\Search\CatalogSearchPublication;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CatalogImportSnapshot
{
    public function prepare(ImportRun $run, array $prepared, array $headers, SpreadsheetReader $reader): int
    {
        $identity = app(CatalogImportIdentity::class);
        $identity->loadExisting();
        $expected = [];
        $vehicles = [];
        for ($offset = 0; $offset < count($prepared['offsets']); $offset += 200) {
            foreach ($reader->readPreparedChunk($prepared, $offset, 200) as $row) {
                foreach ($headers as $column => $header) {
                    $cell = CatalogImportCell::parse($row['_details'][$column] ?? null);
                    if ($cell === null) {
                        continue;
                    }
                    $key = $identity->key($row, $header['part_type_full_slug'], CatalogText::normKey($run->type ?: 'catalog', 'catalog', 60));
                    $fingerprint = mb_strtolower(preg_replace('/\s*([-()])\s*/u', '$1', implode('|', array_slice($row, 1, 5))));
                    if (isset($vehicles[$key]) && $vehicles[$key] !== $fingerprint) {
                        throw new RuntimeException('Коллизия нормализованных автомобилей в строке '.$row['_row_number'].': '.$key);
                    }
                    $vehicles[$key] = $fingerprint;
                    $price = $cell['price'];
                    if (isset($expected[$key]) && $price !== null && $expected[$key]['price'] !== null && $price !== $expected[$key]['price']) {
                        throw new RuntimeException('Противоречивые цены для '.$key.' в строке '.$row['_row_number']);
                    }
                    $expected[$key] = ['import_run_id' => $run->id, 'import_key' => $key, 'price' => $price ?? ($expected[$key]['price'] ?? null)];
                }
            }
        }
        if ($expected === []) {
            throw new RuntimeException('В файле нет ни одного товара. Пустой снимок не применяется автоматически.');
        }
        $source = CatalogText::normKey($run->type ?: 'catalog', 'catalog', 60);
        foreach (array_chunk(array_keys($expected), 500) as $keys) {
            if (Product::withTrashed()->whereIn('import_key', $keys)->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', $source))->exists()) {
                throw new RuntimeException('Ожидаемый импортный ключ уже занят товаром другого источника или ручным товаром.');
            }
        }
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
            Product::where('import_source', $source)->whereIn('id', (clone $items)->select('product_id'))
                ->update(['status' => ProductStatus::Active->value]);
            VehicleGeneration::where('last_import_run_id', $run->id)->update(['is_active' => true]);
            $archived = $archive();
            // Commit the authority marker with the status changes, not in a later job.
            app(CatalogSearchPublication::class)->record($locked);
            $locked->forceFill(['status' => $locked->hasPendingImages() ? ImportRunStatus::ProcessingImages : ImportRunStatus::Done,
                'finished_at' => $locked->hasPendingImages() ? null : now()])->save();

            return $archived;
        });
    }
}
