<?php

namespace App\Filament\Resources\FaqCategories\Pages;

use App\Filament\Resources\FaqCategories\FaqCategoryResource;
use App\Services\StaticContent\FaqAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateFaqCategory extends CreateRecord
{
    protected static string $resource = FaqCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(FaqAdminService::class)->createCategory(FaqCategoryResource::actor(), $data);
        } catch (ValidationException $exception) {
            throw self::mapValidation($exception);
        }
    }

    private static function mapValidation(ValidationException $exception): ValidationException
    {
        return ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
            ->all());
    }
}
