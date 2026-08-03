<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewProducts);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewProducts);
    }

    public function create(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::CreateProducts);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->canPerformAdminAction(AdminPermission::UpdateProducts);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->canPerformAdminAction(AdminPermission::DeleteProducts);
    }

    public function deleteAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::DeleteProducts);
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->canPerformAdminAction(AdminPermission::RestoreProducts);
    }

    public function restoreAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::RestoreProducts);
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function generateVariants(User $user, Product $product): bool
    {
        return $user->canPerformAdminAction(AdminPermission::GenerateProductVariants);
    }

    public function resetGallery(User $user, Product $product): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ResetProductGallery);
    }

    public function replicate(User $user, Product $product): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
