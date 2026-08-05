<?php

namespace App\Filament\Resources\HomepageQuickLinks\Pages;

use App\Filament\Resources\HomepageQuickLinks\HomepageQuickLinkResource;
use App\Models\HomepageQuickLink;
use App\Services\Homepage\HomepageContentAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditHomepageQuickLink extends EditRecord
{
    protected static string $resource = HomepageQuickLinkResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var HomepageQuickLink $record */
        try {
            return app(HomepageContentAdminService::class)->updateQuickLink(HomepageQuickLinkResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }
}
