<?php

namespace App\Filament\Resources\SiteNavigationItems\Pages;

use App\Filament\Resources\SiteNavigationItems\SiteNavigationItemResource;
use App\Models\SiteNavigationItem;
use App\Services\Settings\SiteNavigationAdminService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSiteNavigationItem extends EditRecord
{
    protected static string $resource = SiteNavigationItemResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SiteNavigationItem $record */
        try {
            return app(SiteNavigationAdminService::class)->update(SiteNavigationItemResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(function (SiteNavigationItem $record): void {
                app(SiteNavigationAdminService::class)->delete(SiteNavigationItemResource::actor(), $record);
            }),
        ];
    }
}
