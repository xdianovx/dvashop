<?php

namespace App\Filament\Resources\PaymentMethodSettings\Pages;

use App\Filament\Resources\PaymentMethodSettings\PaymentMethodSettingResource;
use App\Services\Orders\PaymentMethodSettingsAdminService;
use Filament\Resources\Pages\ListRecords;

class ListPaymentMethodSettings extends ListRecords
{
    protected static string $resource = PaymentMethodSettingResource::class;

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        app(PaymentMethodSettingsAdminService::class)->reorder(
            PaymentMethodSettingResource::actor(),
            $order,
        );
    }
}
