<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\ProductImage;
use App\Services\Import\ImportProgressService;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportRunReportExporter
{
    public function logsCsv(ImportRun $run): StreamedResponse
    {
        return response()->streamDownload(function () use ($run): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Дата', 'Уровень', 'Сообщение', 'Контекст']);

            ImportLog::query()
                ->where('import_run_id', $run->getKey())
                ->orderBy('id')
                ->lazyById(500)
                ->each(function (ImportLog $log) use ($handle): void {
                    fputcsv($handle, [
                        $log->created_at?->toDateTimeString(),
                        $log->level?->label() ?? (string) $log->level,
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
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Показатель', 'Значение']);

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
        yield ['ID импорта', $run->getKey()];
        yield ['Файл', $run->original_name];
        yield ['Источник', $run->type];
        yield ['Статус', app(ImportProgressService::class)->statusLabel($run)];
        yield ['Дата загрузки', $run->created_at?->toDateTimeString()];
        yield ['Дата запуска', $run->started_at?->toDateTimeString()];
        yield ['Дата завершения', $run->finished_at?->toDateTimeString()];
        yield ['Всего строк', $run->total_rows];
        yield ['Обработано строк', $run->processed_rows];
        yield ['Создано марок', $run->created_makes];
        yield ['Обновлено марок', $run->updated_makes];
        yield ['Создано моделей', $run->created_models];
        yield ['Обновлено моделей', $run->updated_models];
        yield ['Создано поколений', $run->created_generations];
        yield ['Обновлено поколений', $run->updated_generations];
        yield ['Создано категорий', $run->created_categories];
        yield ['Обновлено категорий', $run->updated_categories];
        yield ['Создано товаров', $run->created_products];
        yield ['Обновлено товаров', $run->updated_products];
        yield ['Архивировано товаров', $run->archived_products];
        yield ['Архивация пропущена', $run->archive_skipped ? 'Да' : 'Нет'];
        yield ['Причина пропуска', $this->archiveSkipReason($run->archive_skip_reason)];
        yield ['Поставлено URL-изображений', $run->queued_images];
        yield ['Обработано изображений', $run->processed_images];
        yield ['Ошибок изображений', $run->failed_images];
        yield ['Привязано дефолтных изображений', $this->productImagesForRun($run, ProductImage::SOURCE_DEFAULT)];
        yield ['Изображений импорта связано', $this->productImagesForRun($run, ProductImage::SOURCE_IMPORT)];
        yield ['Ручных изображений сохранено', $this->productImagesForRun($run, ProductImage::SOURCE_MANUAL)];
        yield ['Предупреждений', $run->warnings_count];
        yield ['Ошибок', $run->errors_count];
        yield ['Последняя ошибка', $run->last_error];
    }

    private function archiveSkipReason(?string $reason): string
    {
        return match ($reason) {
            'row_errors' => 'Ошибки обработки строк',
            null, '' => '',
            default => $reason,
        };
    }

    private function productImagesForRun(ImportRun $run, string $sourceType): int
    {
        return ProductImage::query()
            ->where('source_type', $sourceType)
            ->whereHas('product', function ($query) use ($run): void {
                $query
                    ->where('last_import_run_id', (string) $run->getKey())
                    ->where('import_source', $run->type ?: 'catalog');
            })
            ->count();
    }
}
