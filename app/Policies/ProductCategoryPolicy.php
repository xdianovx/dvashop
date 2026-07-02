<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;

class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function view(User $user, ProductCategory $productCategory): bool
    {
        return $this->canManageCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        return $this->canManageCatalog($user);
    }

    public function delete(User $user, ProductCategory $productCategory): bool
    {
        return $this->canManageCatalog($user);
    }

    public function restore(User $user, ProductCategory $productCategory): bool
    {
        return $this->canManageCatalog($user);
    }

    public function forceDelete(User $user, ProductCategory $productCategory): bool
    {
        return $user->isSuperAdmin();
    }

    private function canManageCatalog(User $user): bool
    {
        return $user->role?->canAccessAdminPanel() ?? false;
    }
}
