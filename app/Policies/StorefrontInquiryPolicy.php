<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\StorefrontInquiry;
use App\Models\User;

class StorefrontInquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewInquiries);
    }

    public function view(User $user, StorefrontInquiry $inquiry): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewInquiries);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StorefrontInquiry $inquiry): bool
    {
        return false;
    }

    public function delete(User $user, StorefrontInquiry $inquiry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, StorefrontInquiry $inquiry): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, StorefrontInquiry $inquiry): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, StorefrontInquiry $inquiry): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
