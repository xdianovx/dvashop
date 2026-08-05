<?php

namespace App\Filament\Resources\DeliveryMethodSettings\Pages;

use App\Filament\Resources\DeliveryMethodSettings\DeliveryMethodSettingResource;
use App\Models\DeliveryMethodSetting;
use App\Services\Orders\DeliveryMethodSettingsAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditDeliveryMethodSetting extends EditRecord
{
    protected static string $resource = DeliveryMethodSettingResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var DeliveryMethodSetting $record */
        try {
            return app(DeliveryMethodSettingsAdminService::class)->update(
                DeliveryMethodSettingResource::actor(),
                $record,
                $data,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }
}
