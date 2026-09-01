<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\HomepageSection;
use App\Models\User;

class HomepageSectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewHomepageContent);
    }

    public function view(User $user, HomepageSection $section): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewHomepageContent);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, HomepageSection $section): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageHomepageContent);
    }

    public function delete(User $user, HomepageSection $section): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, HomepageSection $section): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, HomepageSection $section): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, HomepageSection $section): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
