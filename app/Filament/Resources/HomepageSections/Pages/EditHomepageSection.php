<?php

namespace App\Filament\Resources\HomepageSections\Pages;

use App\Filament\Resources\HomepageSections\HomepageSectionResource;
use App\Models\HomepageSection;
use App\Services\Homepage\HomepageContentAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditHomepageSection extends EditRecord
{
    protected static string $resource = HomepageSectionResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var HomepageSection $record */
        try {
            return app(HomepageContentAdminService::class)->updateSection(HomepageSectionResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }
}
