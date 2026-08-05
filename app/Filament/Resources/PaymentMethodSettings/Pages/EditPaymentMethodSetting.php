<?php

namespace App\Filament\Resources\PaymentMethodSettings\Pages;

use App\Filament\Resources\PaymentMethodSettings\PaymentMethodSettingResource;
use App\Models\PaymentMethodSetting;
use App\Services\Orders\PaymentMethodSettingsAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPaymentMethodSetting extends EditRecord
{
    protected static string $resource = PaymentMethodSettingResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PaymentMethodSetting $record */
        try {
            return app(PaymentMethodSettingsAdminService::class)->update(
                PaymentMethodSettingResource::actor(),
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
