<?php

namespace App\Jobs;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Services\Catalog\CatalogPriceGuard;
use App\Services\Import\CatalogImportProductDefaults;
use App\Services\Import\CatalogImportSnapshot;
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
use RuntimeException;
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
            (new WithoutOverlapping('catalog-import-source-'.ImportRun::query()->whereKey($this->importRunId)->value('type')))
                ->shared()->releaseAfter(10)->expireAfter(630),
        ];
    }

    public function handle(
        SpreadsheetReader $reader,
        ImportStatusService $statusService,
        ImportLogger $logger,
        ImportRowProcessor $rowProcessor,
        ?CatalogImportProductDefaults $productDefaults = null,
    ): void {
        $productDefaults ??= app(CatalogImportProductDefaults::class);
        $run = ImportRun::query()->findOrFail($this->importRunId);

        if ($run->isTerminal()) {
            return;
        }

        if (! in_array($run->status, [ImportRunStatus::Ready, ImportRunStatus::Running, ImportRunStatus::RunningRows], true)) {
            return;
        }

        try {
            $shouldDispatchChunk = false;
            $emptyFile = false;

            DB::transaction(function () use ($reader, $statusService, $logger, $rowProcessor, $productDefaults, &$shouldDispatchChunk, &$emptyFile): void {
                app(CatalogPriceGuard::class)->assertWritable();
                /** @var ImportRun $locked */
                $locked = ImportRun::query()->whereKey($this->importRunId)->lockForUpdate()->firstOrFail();

                if ($locked->initialized_at !== null || ! in_array($locked->status, [ImportRunStatus::Ready, ImportRunStatus::Running, ImportRunStatus::RunningRows], true)) {
                    $logger->info($locked, 'Повторный старт проигнорирован: импорт уже инициализирован или не готов к первичному старту', [
                        'status' => $locked->status?->value,
                        'processed_rows' => $locked->processed_rows,
                        'total_rows' => $locked->total_rows,
                    ]);

                    return;
                }

                $anotherImport = ImportRun::query()->where('type', $locked->type)
                    ->whereKeyNot($locked->getKey())->whereNotNull('initialized_at')
                    ->whereIn('status', [ImportRunStatus::RunningRows, ImportRunStatus::Running,
                        ImportRunStatus::Paused, ImportRunStatus::ProcessingImages])->exists();
                if ($anotherImport) {
                    throw new RuntimeException('Другой импорт этого источника ещё не завершён. Завершите или отмените его перед новой загрузкой.');
                }

                $locked = $statusService->start($locked);

                if (! $locked->status?->isRowsRunning()) {
                    return;
                }

                if ($locked->type === 'catalog') {
                    // Global import prerequisites must be valid before header
                    // resolution or any row-level catalog mutation starts.
                    $productDefaults->canonicalTemplate();
                }

                $absolutePath = Storage::disk('local')->path($locked->stored_path);

                $preparedRows = $reader->prepareRows($absolutePath);
                $headers = $reader->readMergedDetailHeaders($absolutePath);
                if ($headers === []) {
                    throw new RuntimeException('Не найдены колонки деталей. Проверьте заголовки файла.');
                }
                $headers = $rowProcessor->prepareDetailColumns($locked, $headers);
                $totalRows = count($preparedRows['offsets']);
                $preparedRows['expected_products'] = app(CatalogImportSnapshot::class)->prepare($locked, $preparedRows, $headers, $reader);

                $locked->forceFill([
                    'total_rows' => $totalRows,
                    'detail_columns' => $headers,
                    'prepared_rows' => $preparedRows,
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
