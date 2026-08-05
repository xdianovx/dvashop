<?php

namespace App\Filament\Resources\DeliveryMethodSettings\Pages;

use App\Filament\Resources\DeliveryMethodSettings\DeliveryMethodSettingResource;
use App\Services\Orders\DeliveryMethodSettingsAdminService;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryMethodSettings extends ListRecords
{
    protected static string $resource = DeliveryMethodSettingResource::class;

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        app(DeliveryMethodSettingsAdminService::class)->reorder(
            DeliveryMethodSettingResource::actor(),
            $order,
        );
    }
}
