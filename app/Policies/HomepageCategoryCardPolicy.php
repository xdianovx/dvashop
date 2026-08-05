<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\HomepageCategoryCard;
use App\Models\User;

class HomepageCategoryCardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewHomepageContent);
    }

    public function view(User $user, HomepageCategoryCard $card): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewHomepageContent);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, HomepageCategoryCard $card): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageHomepageContent);
    }

    public function delete(User $user, HomepageCategoryCard $card): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, HomepageCategoryCard $card): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, HomepageCategoryCard $card): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, HomepageCategoryCard $card): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageHomepageContent);
    }
}
