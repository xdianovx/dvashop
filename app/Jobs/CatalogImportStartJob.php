<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Services\Import\ImportRowProcessor;
use App\Services\ImportLogger;
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

class CatalogImportStartJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $importRunId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-import-start-'.$this->importRunId))->releaseAfter(10),
        ];
    }

    public function handle(
        SpreadsheetReader $reader,
        ImportStatusService $statusService,
        ImportLogger $logger,
        ImportRowProcessor $rowProcessor,
    ): void {
        $run = ImportRun::query()->findOrFail($this->importRunId);

        if ($run->isTerminal()) {
            return;
        }

        if (! in_array($run->status, [ImportRunStatus::Ready, ImportRunStatus::Paused, ImportRunStatus::Running, ImportRunStatus::RunningRows], true)) {
            return;
        }

        try {
            $shouldDispatchChunk = false;
            $emptyFile = false;

            DB::transaction(function () use ($reader, $statusService, $logger, $rowProcessor, &$shouldDispatchChunk, &$emptyFile): void {
                /** @var ImportRun $locked */
                $locked = ImportRun::query()->whereKey($this->importRunId)->lockForUpdate()->firstOrFail();

                if ($locked->isTerminal()) {
                    return;
                }

                if ($locked->initialized_at !== null) {
                    if ($locked->status?->isRowsRunning() && $locked->processed_rows < $locked->total_rows) {
                        $shouldDispatchChunk = true;
                    }

                    $logger->info($locked, 'Повторный старт проигнорирован: импорт уже инициализирован', [
                        'processed_rows' => $locked->processed_rows,
                        'total_rows' => $locked->total_rows,
                    ]);

                    return;
                }

                $locked = $statusService->start($locked);
                $absolutePath = Storage::disk('local')->path($locked->stored_path);

                $headers = $reader->readMergedDetailHeaders($absolutePath);
                $headers = $rowProcessor->prepareDetailColumns($locked, $headers);
                $totalRows = $reader->countRows($absolutePath, 2);

                $locked->forceFill([
                    'total_rows' => $totalRows,
                    'detail_columns' => $headers,
                    'initialized_at' => now(),
                    'heartbeat_at' => now(),
                ])->save();

                $logger->info($locked, 'Импорт запущен', [
                    'total_rows' => $totalRows,
                    'chunk_size' => $locked->chunk_size,
                ]);

                if ($totalRows === 0) {
                    $emptyFile = true;

                    return;
                }

                $shouldDispatchChunk = true;
            });

            $run = ImportRun::query()->findOrFail($this->importRunId);

            if ($emptyFile) {
                $statusService->markDone($run);
                $logger->warning($run, 'Файл не содержит строк данных');

                return;
            }

            if ($shouldDispatchChunk) {
                CatalogImportChunkJob::dispatch($this->importRunId)->onQueue('imports');
            }
        } catch (Throwable $e) {
            $statusService->fail($run, $e->getMessage());
        }
    }
}
