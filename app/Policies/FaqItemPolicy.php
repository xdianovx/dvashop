<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\FaqItem;
use App\Models\User;

class FaqItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function view(User $user, FaqItem $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function create(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function update(User $user, FaqItem $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function delete(User $user, FaqItem $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function deleteAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function restore(User $user, FaqItem $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function restoreAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function forceDelete(User $user, FaqItem $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, FaqItem $record): bool
    {
        return false;
    }
}
