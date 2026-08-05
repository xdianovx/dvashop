<?php

namespace App\Filament\Resources\PaymentMethodSettings\Pages;

use App\Filament\Resources\PaymentMethodSettings\PaymentMethodSettingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentMethodSetting extends ViewRecord
{
    protected static string $resource = PaymentMethodSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
