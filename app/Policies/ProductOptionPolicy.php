<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class ProductOptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewCatalog);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewCatalog);
    }

    public function create(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::CreateCatalog);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->canPerformAdminAction(AdminPermission::UpdateCatalog);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $model): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::UpdateCatalog);
    }
}
