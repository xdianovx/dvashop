<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\HomepageMetric;
use App\Models\User;

class HomepageMetricPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewHomepageContent);
    }

    public function view(User $user, HomepageMetric $metric): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ViewHomepageContent);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, HomepageMetric $metric): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageHomepageContent);
    }

    public function delete(User $user, HomepageMetric $metric): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, HomepageMetric $metric): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, HomepageMetric $metric): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, HomepageMetric $metric): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->canPerformAdminAction(AdminPermission::ManageHomepageContent);
    }
}
