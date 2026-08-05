<?php

namespace App\Filament\Resources\StaticPageSections\Pages;

use App\Filament\Resources\StaticPageSections\StaticPageSectionResource;
use App\Models\StaticPageSection;
use App\Services\StaticContent\StaticPageContentAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditStaticPageSection extends EditRecord
{
    protected static string $resource = StaticPageSectionResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StaticPageSection $record */
        try {
            return app(StaticPageContentAdminService::class)->updateSection(StaticPageSectionResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }
}
