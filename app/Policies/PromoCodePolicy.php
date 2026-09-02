<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\PromoCode;
use App\Models\User;

class PromoCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewPromoCodes);
    }

    public function view(User $user, PromoCode $promoCode): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewPromoCodes);
    }

    public function create(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManagePromoCodes);
    }

    public function update(User $user, PromoCode $promoCode): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManagePromoCodes);
    }

    public function delete(User $user, PromoCode $promoCode): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManagePromoCodes);
    }

    public function deleteAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManagePromoCodes);
    }

    public function restore(User $user, PromoCode $promoCode): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManagePromoCodes);
    }

    public function restoreAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManagePromoCodes);
    }

    public function forceDelete(User $user, PromoCode $promoCode): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, PromoCode $promoCode): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
