<?php

namespace App\Filament\Resources\FaqItems\Pages;

use App\Filament\Resources\FaqItems\FaqItemResource;
use App\Models\FaqCategory;
use App\Services\StaticContent\FaqAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateFaqItem extends CreateRecord
{
    protected static string $resource = FaqItemResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $categoryId = $data['faq_category_id'] ?? null;
        unset($data['faq_category_id']);
        $category = is_numeric($categoryId) ? FaqCategory::query()->find((int) $categoryId) : null;
        if ($category === null) {
            throw ValidationException::withMessages(['data.faq_category_id' => 'Выбранная категория FAQ не существует или удалена.']);
        }

        try {
            return app(FaqAdminService::class)->createItem(FaqItemResource::actor(), $category, $data);
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
