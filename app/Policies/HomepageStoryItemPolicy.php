<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\HomepageStoryItem;
use App\Models\User;

class HomepageStoryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewHomepageContent);
    }

    public function view(User $user, HomepageStoryItem $item): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageHomepageContent);
    }

    public function update(User $user, HomepageStoryItem $item): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, HomepageStoryItem $item): bool
    {
        return $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    public function reorder(User $user): bool
    {
        return $this->create($user);
    }

    public function restore(User $user, HomepageStoryItem $item): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, HomepageStoryItem $item): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, HomepageStoryItem $item): bool
    {
        return false;
    }
}
