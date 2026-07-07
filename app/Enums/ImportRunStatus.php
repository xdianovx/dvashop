<?php

namespace App\Enums;

enum ImportRunStatus: string
{
    case Ready = 'ready';
    case Running = 'running';
    case RunningRows = 'running_rows';
    case ProcessingImages = 'processing_images';
    case Paused = 'paused';
    case Failed = 'failed';
    case Done = 'done';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Готов к запуску',
            self::Running => 'Выполняется',
            self::RunningRows => 'Обработка строк',
            self::ProcessingImages => 'Обработка изображений',
            self::Paused => 'Пауза',
            self::Failed => 'Ошибка',
            self::Done => 'Завершён',
            self::Canceled => 'Отменён',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ready => 'gray',
            self::Running, self::RunningRows, self::ProcessingImages => 'info',
            self::Paused => 'warning',
            self::Failed, self::Canceled => 'danger',
            self::Done => 'success',
        };
    }

    public function isRowsRunning(): bool
    {
        return in_array($this, [self::Running, self::RunningRows], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::RunningRows, self::ProcessingImages, self::Paused], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Done, self::Canceled], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->reject(fn (self $status): bool => $status === self::Running)
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
