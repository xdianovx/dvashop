<?php

namespace App\Filament\Resources\FaqItems\Pages;

use App\Filament\Resources\FaqItems\FaqItemResource;
use App\Models\FaqItem;
use App\Services\StaticContent\FaqAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditFaqItem extends EditRecord
{
    protected static string $resource = FaqItemResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var FaqItem $record */
        try {
            return app(FaqAdminService::class)->updateItem(FaqItemResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw self::mapValidation($exception);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->successNotificationTitle('Вопрос FAQ удалён')
                ->failureNotificationTitle('Не удалось удалить вопрос FAQ')
                ->using(fn (FaqItem $record): bool => app(FaqAdminService::class)->deleteItem(FaqItemResource::actor(), $record)),
            RestoreAction::make()->using(fn (FaqItem $record): FaqItem => app(FaqAdminService::class)->restoreItem(FaqItemResource::actor(), $record)),
        ];
    }

    private static function mapValidation(ValidationException $exception): ValidationException
    {
        return ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
            ->all());
    }
}
