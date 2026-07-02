<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use App\Services\SpreadsheetReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
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
    ): void {
        $run = ImportRun::query()->findOrFail($this->importRunId);

        if ($run->isTerminal()) {
            return;
        }

        if (! in_array($run->status, [ImportRunStatus::Ready, ImportRunStatus::Paused, ImportRunStatus::Running], true)) {
            return;
        }

        try {
            $statusService->start($run);
            $absolutePath = Storage::disk('local')->path($run->stored_path);

            $headers = $reader->readMergedDetailHeaders($absolutePath);
            $totalRows = $reader->countRows($absolutePath, 2);

            $run->forceFill([
                'total_rows' => $totalRows,
                'detail_columns' => $headers,
                'heartbeat_at' => now(),
            ])->save();

            $logger->info($run, 'Импорт запущен', [
                'total_rows' => $totalRows,
                'chunk_size' => $run->chunk_size,
            ]);

            if ($totalRows === 0) {
                $statusService->markDone($run);
                $logger->warning($run, 'Файл не содержит строк данных');

                return;
            }

            CatalogImportChunkJob::dispatch($run->getKey())->onQueue('imports');
        } catch (Throwable $e) {
            $statusService->fail($run, $e->getMessage());
        }
    }
}
