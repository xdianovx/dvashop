<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\StaticPage;
use App\Models\User;

class StaticPagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function view(User $user, StaticPage $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StaticPage $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function delete(User $user, StaticPage $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, StaticPage $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, StaticPage $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, StaticPage $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }
}
