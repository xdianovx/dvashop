<?php

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Filament\Pages\BasePage;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReportValidationFailure
{
    public function __invoke(mixed $component, Throwable $exception): void
    {
        if (! ($component instanceof BasePage || $component instanceof RelationManager)
            || ! $exception instanceof ValidationException) {
            return;
        }

        // Livewire keeps the original field errors and handles the exception.
        // A summary also covers domain keys without a matching visible field,
        // collapsed repeaters, and table actions that have no form at all.
        $messages = collect($exception->errors())->flatten()->unique();

        Notification::make()
            ->danger()
            ->title('Не удалось выполнить действие')
            ->body(new HtmlString($messages->map(fn (string $message): string => e($message))->implode('<br>')))
            ->persistent()
            ->send();
    }
}
