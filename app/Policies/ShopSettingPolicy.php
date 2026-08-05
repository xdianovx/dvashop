<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\ShopSetting;
use App\Models\User;

class ShopSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStoreSettings);
    }

    public function view(User $user, ShopSetting $setting): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStoreSettings);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ShopSetting $setting): bool
    {
        return $user->canPerformAdminAction(AdminPermission::UpdateStoreSettings);
    }

    public function delete(User $user, ShopSetting $setting): bool
    {
        return false;
    }

    public function forceDelete(User $user, ShopSetting $setting): bool
    {
        return false;
    }

    public function replicate(User $user, ShopSetting $setting): bool
    {
        return false;
    }
}
