<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageUsers($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->canManageUsers($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageUsers($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->canManageUsers($user);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, User $model): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    private function canManageUsers(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageUsers);
    }
}
