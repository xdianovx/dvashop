<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Models\ImportRun;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportRunReportExporter
{
    public function logsCsv(ImportRun $run): StreamedResponse
    {
        return response()->streamDownload(function () use ($run): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['id', 'created_at', 'level', 'message', 'context']);

            ImportLog::query()
                ->where('import_run_id', $run->getKey())
                ->orderBy('id')
                ->lazyById(500)
                ->each(function (ImportLog $log) use ($handle): void {
                    fputcsv($handle, [
                        $log->getKey(),
                        $log->created_at?->toDateTimeString(),
                        $log->level?->value ?? (string) $log->level,
                        $log->message,
                        $log->context === null ? '' : json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                });

            fclose($handle);
        }, 'import-'.$run->getKey().'-logs.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function summaryCsv(ImportRun $run): StreamedResponse
    {
        return response()->streamDownload(function () use ($run): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['metric', 'value']);

            foreach ($this->summaryRows($run) as [$metric, $value]) {
                fputcsv($handle, [$metric, $value]);
            }

            fclose($handle);
        }, 'import-'.$run->getKey().'-report.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return Generator<int, array{0:string, 1:mixed}> */
    private function summaryRows(ImportRun $run): Generator
    {
        yield ['id', $run->getKey()];
        yield ['file', $run->original_name];
        yield ['type', $run->type];
        yield ['status', $run->status?->value ?? (string) $run->status];
        yield ['created_at', $run->created_at?->toDateTimeString()];
        yield ['started_at', $run->started_at?->toDateTimeString()];
        yield ['finished_at', $run->finished_at?->toDateTimeString()];
        yield ['processed_rows', $run->processed_rows];
        yield ['total_rows', $run->total_rows];
        yield ['created_makes', $run->created_makes];
        yield ['updated_makes', $run->updated_makes];
        yield ['created_models', $run->created_models];
        yield ['updated_models', $run->updated_models];
        yield ['created_generations', $run->created_generations];
        yield ['updated_generations', $run->updated_generations];
        yield ['created_categories', $run->created_categories];
        yield ['updated_categories', $run->updated_categories];
        yield ['created_products', $run->created_products];
        yield ['updated_products', $run->updated_products];
        yield ['archived_products', $run->archived_products];
        yield ['queued_images', $run->queued_images];
        yield ['processed_images', $run->processed_images];
        yield ['failed_images', $run->failed_images];
        yield ['warnings_count', $run->warnings_count];
        yield ['errors_count', $run->errors_count];
        yield ['last_error', $run->last_error];
    }
}
