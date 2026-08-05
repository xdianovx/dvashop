<?php

namespace App\Filament\Resources\FaqCategories\Pages;

use App\Filament\Resources\FaqCategories\FaqCategoryResource;
use App\Models\FaqCategory;
use App\Services\StaticContent\FaqAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditFaqCategory extends EditRecord
{
    protected static string $resource = FaqCategoryResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var FaqCategory $record */
        try {
            return app(FaqAdminService::class)->updateCategory(FaqCategoryResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw self::mapValidation($exception);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->successNotificationTitle('Категория FAQ удалена')
                ->failureNotificationTitle('Не удалось удалить категорию FAQ')
                ->using(fn (FaqCategory $record): bool => app(FaqAdminService::class)->deleteCategory(FaqCategoryResource::actor(), $record)),
            RestoreAction::make()->using(fn (FaqCategory $record): FaqCategory => app(FaqAdminService::class)->restoreCategory(FaqCategoryResource::actor(), $record)),
        ];
    }

    private static function mapValidation(ValidationException $exception): ValidationException
    {
        return ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
            ->all());
    }
}
