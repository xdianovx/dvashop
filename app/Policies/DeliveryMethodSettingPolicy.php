<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\DeliveryMethodSetting;
use App\Models\User;

class DeliveryMethodSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewDeliveryMethods);
    }

    public function view(User $user, DeliveryMethodSetting $setting): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewDeliveryMethods);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DeliveryMethodSetting $setting): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, DeliveryMethodSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, DeliveryMethodSetting $setting): bool
    {
        return false;
    }

    public function forceDelete(User $user, DeliveryMethodSetting $setting): bool
    {
        return false;
    }

    public function replicate(User $user, DeliveryMethodSetting $setting): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageDeliveryMethods);
    }
}
