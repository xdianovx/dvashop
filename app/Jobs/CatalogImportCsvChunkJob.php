<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\Product;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\PublicCatalogCache;
use App\Services\Search\CatalogSearchSync;
use App\Services\Search\CatalogSearchPublication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Bounded, checkpointed CSV price/variant updates; unchanged products incur no variant writes. */
final class CatalogImportCsvChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    private const BATCH = 50;

    public function __construct(public int $importRunId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('catalog-import-csv-'.$this->importRunId))
            ->releaseAfter(10)->expireAfter(630)];
    }

    public function handle(ImportStatusService $status, ImportLogger $logger): void
    {
        $run = ImportRun::findOrFail($this->importRunId);
        if ($run->import_mode !== 'csv' || ! $run->status?->isRowsRunning()
            || ! ($run->prepared_rows['csv_only'] ?? false)) {
            return;
        }
        $matrix = app(CatalogConfigurations::class);
        try {
            if ((string) ($run->prepared_rows['source_sha256'] ?? '') !== app(\App\Services\Catalog\CatalogMatrixPriceHistory::class)->sourceSha256()) {
                throw new RuntimeException('Во время импорта изменился установленный источник цен.');
            }
            $matrix->prime(DB::table('catalog_configurations')->distinct()->pluck('part_type_id')->all());
            $lastId = (int) ($run->prepared_rows['last_product_id'] ?? 0);
            $products = Product::where('import_source', $run->type)->whereNotNull('import_key')
                ->where('id', '>', $lastId)->orderBy('id')->limit(self::BATCH)->get();
            if ($products->isEmpty()) {
                DB::transaction(function () use ($run): void {
                    app(CatalogSearchPublication::class)->record($run);
                    $run->refresh();
                });
                $status->markDone($run);
                app(PublicCatalogCache::class)->invalidateVehicleNavigation();
                return;
            }
            foreach ($products as $index => $product) {
                if ($index % 20 === 0 && ! $run->fresh()->status?->isRowsRunning()) {
                    return;
                }
                CatalogSearchSync::withoutSyncing(function () use ($product, $matrix, $run): void {
                    DB::transaction(function () use ($product, $matrix, $run): void {
                        $matrix->synchronize($product, 'import:'.$run->id);
                        $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                        $progress = $locked->prepared_rows ?? [];
                        if ((int) ($progress['last_product_id'] ?? 0) >= $product->id) {
                            return;
                        }
                        $progress['last_product_id'] = $product->id;
                        $locked->forceFill([
                            'prepared_rows' => $progress,
                            'current_row' => min($locked->total_rows, $locked->current_row + 1),
                            'processed_rows' => min($locked->total_rows, $locked->processed_rows + 1),
                            'heartbeat_at' => now(),
                        ])->save();
                    });
                });
            }
            $logger->info($run, 'Обработана порция товаров CSV', ['last_product_id' => $products->last()->id, 'processed' => count($products)]);
            CatalogImportCsvChunkJob::dispatch($run->id)->onQueue('imports');
        } catch (Throwable $e) {
            $status->fail($run->fresh(), $e->getMessage());
        } finally {
            $matrix->clear();
        }
    }
}
