<?php

namespace App\Filament\Resources\StaticPages\Pages;

use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Models\StaticPage;
use App\Services\StaticContent\StaticPageContentAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditStaticPage extends EditRecord
{
    protected static string $resource = StaticPageResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StaticPage $record */
        try {
            return app(StaticPageContentAdminService::class)->updatePage(StaticPageResource::actor(), $record, $data);
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
