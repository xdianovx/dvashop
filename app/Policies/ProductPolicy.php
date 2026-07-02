<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageProducts($user);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->canManageProducts($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageProducts($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->canManageProducts($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->canManageProducts($user);
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->canManageProducts($user);
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->isSuperAdmin();
    }

    private function canManageProducts(User $user): bool
    {
        return $user->role?->canAccessAdminPanel() ?? false;
    }
}
