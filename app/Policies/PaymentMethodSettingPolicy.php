<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\PaymentMethodSetting;
use App\Models\User;

class PaymentMethodSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewPaymentMethods);
    }

    public function view(User $user, PaymentMethodSetting $setting): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewPaymentMethods);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentMethodSetting $setting): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, PaymentMethodSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, PaymentMethodSetting $setting): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaymentMethodSetting $setting): bool
    {
        return false;
    }

    public function replicate(User $user, PaymentMethodSetting $setting): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManagePaymentMethods);
    }
}
