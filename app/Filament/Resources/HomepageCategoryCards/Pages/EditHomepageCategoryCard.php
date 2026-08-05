<?php

namespace App\Filament\Resources\HomepageCategoryCards\Pages;

use App\Filament\Resources\HomepageCategoryCards\HomepageCategoryCardResource;
use App\Models\HomepageCategoryCard;
use App\Services\Homepage\HomepageContentAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditHomepageCategoryCard extends EditRecord
{
    protected static string $resource = HomepageCategoryCardResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var HomepageCategoryCard $record */
        try {
            return app(HomepageContentAdminService::class)->updateCategoryCard(HomepageCategoryCardResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }
}
