<?php

namespace App\Filament\Resources\StaticPageItems\Pages;

use App\Filament\Resources\StaticPageItems\StaticPageItemResource;
use App\Models\StaticPageItem;
use App\Services\StaticContent\StaticPageContentAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditStaticPageItem extends EditRecord
{
    protected static string $resource = StaticPageItemResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StaticPageItem $record */
        try {
            return app(StaticPageContentAdminService::class)->updateItem(StaticPageItemResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }
}
