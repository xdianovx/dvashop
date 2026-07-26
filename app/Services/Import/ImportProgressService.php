<?php

namespace App\Services\Import;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;

final class ImportProgressService
{
    public function forRun(ImportRun $run): ImportProgress
    {
        $rowsPercent = $this->percent($run->processed_rows, $run->total_rows);
        $finishedImages = $run->processed_images + $run->failed_images;
        $imagesPercent = $this->percent($finishedImages, $run->queued_images);
        $overallPercent = $this->overallPercent($run, $rowsPercent, $finishedImages);

        return new ImportProgress(
            rowsPercent: $rowsPercent,
            imagesPercent: $imagesPercent,
            overallPercent: $overallPercent,
            rowsLabel: "Обработано строк: {$run->processed_rows} из {$run->total_rows}",
            imagesLabel: $run->queued_images > 0
                ? "Обработано изображений: {$finishedImages} из {$run->queued_images}; ошибок: {$run->failed_images}"
                : 'Изображения не найдены или не требуются',
            overallLabel: "Общая готовность: {$overallPercent}%",
            stageLabel: $this->stageLabel($run),
            statusLabel: $this->statusLabel($run),
        );
    }

    public function statusLabel(ImportRun $run): string
    {
        if ($run->status === ImportRunStatus::Done && ($run->warnings_count > 0 || $run->errors_count > 0 || $run->failed_images > 0)) {
            return 'Завершён с предупреждениями';
        }

        return $run->status?->label() ?? 'Статус не указан';
    }

    private function overallPercent(ImportRun $run, int $rowsPercent, int $finishedImages): int
    {
        if ($run->status === ImportRunStatus::Done) {
            return 100;
        }

        if ($run->queued_images <= 0) {
            return min(99, $rowsPercent);
        }

        $totalWork = max(1, $run->total_rows + $run->queued_images);
        $finishedWork = min($run->total_rows, $run->processed_rows) + min($run->queued_images, $finishedImages);

        return min(99, (int) floor(($finishedWork / $totalWork) * 100));
    }

    private function stageLabel(ImportRun $run): string
    {
        return match ($run->status) {
            ImportRunStatus::Ready => 'Ожидает запуска',
            ImportRunStatus::Running, ImportRunStatus::RunningRows => 'Чтение и обработка строк',
            ImportRunStatus::ProcessingImages => 'Скачивание и обработка изображений',
            ImportRunStatus::Paused => $run->processed_rows >= $run->total_rows && $run->hasPendingImages()
                ? 'Обработка изображений приостановлена'
                : 'Обработка строк приостановлена',
            ImportRunStatus::Done => 'Импорт завершён',
            ImportRunStatus::Failed => 'Импорт остановлен из-за ошибки',
            ImportRunStatus::Canceled => 'Импорт отменён администратором',
            default => 'Подготовка импорта',
        };
    }

    private function percent(int $completed, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return min(100, (int) floor(($completed / $total) * 100));
    }
}
