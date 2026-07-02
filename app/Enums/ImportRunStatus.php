<?php

namespace App\Enums;

enum ImportRunStatus: string
{
    case Ready = 'ready';
    case Running = 'running';
    case Paused = 'paused';
    case Failed = 'failed';
    case Done = 'done';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Готов к запуску',
            self::Running => 'Выполняется',
            self::Paused => 'Пауза',
            self::Failed => 'Ошибка',
            self::Done => 'Завершён',
            self::Canceled => 'Отменён',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Done, self::Canceled], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
