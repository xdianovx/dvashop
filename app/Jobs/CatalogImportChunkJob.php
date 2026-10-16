<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Import\CatalogImportSnapshot;
use App\Services\Import\ImportProductFactory;
use App\Services\Import\ImportRowProcessor;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\PublicCatalogCache;
use App\Services\Search\CatalogSearchSync;
use App\Services\SpreadsheetReader;
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

class CatalogImportChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    private const PROGRESS_CHECKPOINT_SIZE = 20;

    private const OVERLAP_LOCK_EXPIRES_AFTER = 630;

    public function __construct(public int $importRunId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-import-chunk-'.$this->importRunId))
                ->releaseAfter(10)
                ->expireAfter(self::OVERLAP_LOCK_EXPIRES_AFTER),
            (new WithoutOverlapping('catalog-import-source-'.ImportRun::whereKey($this->importRunId)->value('type')))
                ->shared()->releaseAfter(10)->expireAfter(self::OVERLAP_LOCK_EXPIRES_AFTER),
        ];
    }

    public function handle(
        SpreadsheetReader $reader,
        ImportStatusService $statusService,
        ImportLogger $logger,
        ImportRowProcessor $rowProcessor,
        ImportProductFactory $products,
    ): void {
        CatalogSearchSync::withoutSyncing(fn () => $this->processChunk($reader, $statusService, $logger, $rowProcessor, $products));
    }

    private function processChunk(
        SpreadsheetReader $reader,
        ImportStatusService $statusService,
        ImportLogger $logger,
        ImportRowProcessor $rowProcessor,
        ImportProductFactory $products,
    ): void {
        $run = ImportRun::query()->findOrFail($this->importRunId);

        if (! $run->status?->isRowsRunning()) {
            return;
        }

        if (isset($run->prepared_rows['catalog_mode']) || ($run->initialized_at !== null && ! $run->isSnapshotCatalogImport())) {
            $statusService->fail($run, 'Подготовка выполнена старой версией импорта. Загрузите файл заново для полной проверки.');

            return;
        }
        $catalogMutated = false;

        try {
            $absolutePath = Storage::disk('local')->path($run->stored_path);
            $offset = (int) $run->current_row;
            $limit = (int) $run->chunk_size;
            $rows = $run->prepared_rows !== null
                ? $reader->readPreparedChunk($run->prepared_rows, $offset, $limit)
                : $reader->readChunk($absolutePath, $offset, $limit, 2);
            $processed = count($rows);

            if ($processed === 0 && $run->isSnapshotCatalogImport() && $offset < $run->total_rows) {
                throw new RuntimeException('Подготовленные строки закончились до завершения импорта.');
            }

            // A retry after the final progress checkpoint must still publish the snapshot.
            if ($processed === 0 && ! $run->isSnapshotCatalogImport()) {
                $statusService->markRowsDone($run);
                $logger->info($run, 'Строки импорта завершены', [
                    'queued_images' => $run->fresh()->queued_images,
                ]);

                return;
            }

            $processedInChunk = 0;

            if (app(CatalogConfigurations::class)->ready()) {
                app(CatalogConfigurations::class)->prime(array_column($run->detail_columns ?? [], 'part_type_id'));
            }
            foreach ($rows as $index => $row) {
                $rowNumber = $row['_row_number'] ?? ($offset + $index + 3);

                try {
                    DB::transaction(function () use ($rowProcessor, $run, $row, $rowNumber): void {
                        $rowProcessor->process(
                            run: $run,
                            row: $row,
                            detailColumns: $run->detail_columns ?? [],
                            rowNumber: $rowNumber,
                        );
                    });
                    $catalogMutated = true;
                } catch (Throwable $e) {
                    $logger->error($run, 'Ошибка обработки строки импорта', [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ]);
                }

                $processedInChunk++;

                if ($processedInChunk % self::PROGRESS_CHECKPOINT_SIZE === 0) {
                    $run = $statusService->updateRowsProgress($run, $offset + $processedInChunk);

                    if (! $run->status?->isRowsRunning()) {
                        return;
                    }
                }
            }

            $run = $statusService->updateRowsProgress($run, $offset + $processedInChunk);

            if (! $run->status?->isRowsRunning()) {
                return;
            }

            $logger->info($run, 'Чанк прочитан', [
                'offset' => $offset,
                'count' => $processedInChunk,
                'processed_rows' => $run->processed_rows,
                'total_rows' => $run->total_rows,
            ]);

            if ($run->processed_rows >= $run->total_rows) {
                $archived = 0;
                $run->refresh();

                if ($run->errors_count > 0) {
                    $run->forceFill([
                        'archive_skipped' => true,
                        'archive_skip_reason' => 'row_errors',
                    ])->save();

                    $logger->warning($run, 'Автоархивация пропущена, потому что во время импорта были ошибки строк.', [
                        'errors_count' => $run->errors_count,
                    ]);
                    $statusService->failFromRowErrors($run);

                    return;
                }

                if ($run->status?->isRowsRunning()) {
                    $archived = $run->isSnapshotCatalogImport()
                        ? app(CatalogImportSnapshot::class)->finalize($run, fn (): int => $products->archiveMissingProducts($run))
                        : $products->archiveMissingProducts($run);
                    $catalogMutated = true;
                }

                $statusService->markRowsDone($run);
                $logger->info($run, 'Строки импорта завершены', [
                    'archived_products' => $archived,
                    'archive_skipped' => (bool) $run->fresh()->archive_skipped,
                    'queued_images' => $run->fresh()->queued_images,
                ]);

                return;
            }

            $run->refresh();
            if ($run->status?->isRowsRunning()) {
                CatalogImportChunkJob::dispatch($run->getKey())->onQueue('imports');
            }
        } catch (Throwable $e) {
            // A logging/notification failure after the atomic publication must not
            // reclassify an already applied snapshot as a failed row import.
            if ($run->isSnapshotCatalogImport() && in_array($run->fresh()->status, [ImportRunStatus::Done, ImportRunStatus::ProcessingImages], true)) {
                report($e);

                return;
            }
            $statusService->fail($run, $e->getMessage());
        } finally {
            if ($catalogMutated) {
                app(PublicCatalogCache::class)->invalidateVehicleNavigation();
            }
        }
    }
}
