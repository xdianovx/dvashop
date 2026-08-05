<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\StaticPageSection;
use App\Models\User;

class StaticPageSectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function view(User $user, StaticPageSection $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StaticPageSection $record): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function delete(User $user, StaticPageSection $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, StaticPageSection $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, StaticPageSection $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, StaticPageSection $record): bool
    {
        return false;
    }
}
