<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\Product;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\Import\CatalogSourceContract;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** A configuration-only run never touches vehicle presence or archives products. */
final class CatalogImportCsvStartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $importRunId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('catalog-import-source-catalog'))
            ->shared()->releaseAfter(10)->expireAfter(630)];
    }

    public function handle(ImportStatusService $status, ImportLogger $logger): void
    {
        $run = ImportRun::findOrFail($this->importRunId);
        if ($run->import_mode !== 'csv' || ! $run->status?->isRowsRunning() || $run->initialized_at !== null) {
            return;
        }
        try {
            $total = DB::transaction(function () use ($run, $logger): int {
                app(CatalogPriceGuard::class)->assertWritable();
                $locked = ImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->initialized_at !== null || ! $locked->status?->isRowsRunning()) {
                    return 0;
                }
                if (ImportRun::where('type', $locked->type)->whereKeyNot($locked->id)
                    ->whereIn('status', ['running', 'running_rows', 'paused'])->exists()) {
                    throw new RuntimeException('Другой импорт каталога ещё выполняется.');
                }
                $csvPath = Storage::disk('local')->path($locked->stored_path);
                if (! is_file($csvPath) || ! hash_equals((string) $locked->file_hash, (string) hash_file('sha256', $csvPath))) {
                    throw new RuntimeException('CSV был изменён или удалён после загрузки.');
                }
                // CSV-only changes are allowed only against the latest successful Excel schema.
                $excel = ImportRun::where('type', $locked->type)
                    ->whereIn('import_mode', ['excel', 'full'])
                    ->where('status', ImportRunStatus::Done->value)
                    ->whereNotNull('detail_columns')->latest('id')->first();
                if (! $excel) {
                    throw new RuntimeException('Для обновления только CSV сначала завершите полный импорт Excel + CSV.');
                }
                $paths = [];
                foreach ($excel->detail_columns ?? [] as $column) {
                    if (! empty($column['part_type_full_slug'])) {
                        $paths[$column['part_type_full_slug']] = $column['part_type_full_title'] ?? $column['part_type_full_slug'];
                    }
                }
                app(CatalogSourceContract::class)->validatePaths($paths, $csvPath);
                $installed = app(CatalogConfigurations::class)->install($csvPath, authorizedImportId: $locked->id);
                $count = Product::where('import_source', $locked->type)->whereNotNull('import_key')->count();
                $locked->forceFill([
                    'total_rows' => $count, 'processed_rows' => 0, 'current_row' => 0,
                    'prepared_rows' => ['csv_only' => true, 'last_product_id' => 0, 'source_sha256' => $installed['source_sha256']],
                    'initialized_at' => now(), 'heartbeat_at' => now(),
                ])->save();
                $logger->info($locked, 'CSV установлен, начата безопасная синхронизация вариантов', [
                    'products' => $count, 'source_rows' => $installed['source_rows'], 'source_sha256' => $installed['source_sha256'],
                ]);
                return $count;
            });
            if ($total === 0) {
                if ($run->fresh()->initialized_at !== null) {
                    $status->markDone($run);
                }
                return;
            }
            CatalogImportCsvChunkJob::dispatch($run->id)->onQueue('imports');
        } catch (Throwable $e) {
            $status->fail($run, $e->getMessage());
        }
    }
}
