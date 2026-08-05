<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\SiteNavigationItem;
use App\Models\User;

class SiteNavigationItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewSiteNavigation);
    }

    public function view(User $user, SiteNavigationItem $item): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewSiteNavigation);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SiteNavigationItem $item): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, SiteNavigationItem $item): bool
    {
        return $this->canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function reorder(User $user): bool
    {
        return $this->canManage($user);
    }

    public function restore(User $user, SiteNavigationItem $item): bool
    {
        return false;
    }

    public function forceDelete(User $user, SiteNavigationItem $item): bool
    {
        return false;
    }

    public function replicate(User $user, SiteNavigationItem $item): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageSiteNavigation);
    }
}
