<?php

namespace App\Services\Orders;

use App\Models\ShopSetting;

class OrderNotificationSettings
{
    public function storeName(): string
    {
        $storeName = trim((string) $this->settings()?->store_name);

        return $storeName !== '' ? $storeName : 'AVTOPOROGI.ru';
    }

    public function managerEmail(): ?string
    {
        $databaseEmail = trim((string) $this->settings()?->order_notification_email);

        if ($databaseEmail !== '') {
            return $databaseEmail;
        }

        $fallback = trim((string) config('shop.orders_manager_email'));

        return $fallback !== '' ? $fallback : null;
    }

    private function settings(): ?ShopSetting
    {
        return ShopSetting::query()
            ->where('singleton_key', ShopSetting::SINGLETON_KEY)
            ->first(['store_name', 'order_notification_email']);
    }
}
