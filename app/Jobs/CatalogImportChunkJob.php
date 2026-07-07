<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Services\ImportLogger;
use App\Services\Import\ImportProductFactory;
use App\Services\Import\ImportRowProcessor;
use App\Services\ImportStatusService;
use App\Services\SpreadsheetReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CatalogImportChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $importRunId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-import-chunk-'.$this->importRunId))->releaseAfter(10),
        ];
    }

    public function handle(
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

        try {
            $absolutePath = Storage::disk('local')->path($run->stored_path);
            $offset = (int) $run->current_row;
            $limit = (int) $run->chunk_size;
            $rows = $reader->readChunk($absolutePath, $offset, $limit, 2);
            $processed = count($rows);

            if ($processed === 0) {
                $statusService->markRowsDone($run);
                $logger->info($run, 'Строки импорта завершены', [
                    'queued_images' => $run->fresh()->queued_images,
                ]);

                return;
            }

            foreach ($rows as $index => $row) {
                $rowNumber = $offset + $index + 3;

                try {
                    DB::transaction(function () use ($rowProcessor, $run, $row, $rowNumber): void {
                        $rowProcessor->process(
                            run: $run,
                            row: $row,
                            detailColumns: $run->detail_columns ?? [],
                            rowNumber: $rowNumber,
                        );
                    });
                } catch (Throwable $e) {
                    $logger->error($run, 'Ошибка обработки строки импорта', [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $run->refresh();
            $run->forceFill([
                'processed_rows' => min($run->total_rows, $run->processed_rows + $processed),
                'current_row' => min($run->total_rows, $offset + $processed),
                'heartbeat_at' => now(),
            ])->save();

            $logger->info($run, 'Чанк прочитан', [
                'offset' => $offset,
                'count' => $processed,
                'processed_rows' => $run->processed_rows,
                'total_rows' => $run->total_rows,
            ]);

            if ($run->processed_rows >= $run->total_rows) {
                $archived = $products->archiveMissingProducts($run);
                $statusService->markRowsDone($run);
                $logger->info($run, 'Строки импорта завершены', [
                    'archived_products' => $archived,
                    'queued_images' => $run->fresh()->queued_images,
                ]);

                return;
            }

            $run->refresh();
            if ($run->status?->isRowsRunning()) {
                CatalogImportChunkJob::dispatch($run->getKey())->onQueue('imports');
            }
        } catch (Throwable $e) {
            $statusService->fail($run, $e->getMessage());
        }
    }
}
