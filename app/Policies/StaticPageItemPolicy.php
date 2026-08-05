<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\StaticPageItem;
use App\Models\User;

class StaticPageItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function view(User $user, StaticPageItem $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StaticPageItem $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function delete(User $user, StaticPageItem $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, StaticPageItem $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, StaticPageItem $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, StaticPageItem $record): bool
    {
        return false;
    }
}
