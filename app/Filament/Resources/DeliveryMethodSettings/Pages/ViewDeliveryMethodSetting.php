<?php

namespace App\Filament\Resources\DeliveryMethodSettings\Pages;

use App\Filament\Resources\DeliveryMethodSettings\DeliveryMethodSettingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDeliveryMethodSetting extends ViewRecord
{
    protected static string $resource = DeliveryMethodSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
