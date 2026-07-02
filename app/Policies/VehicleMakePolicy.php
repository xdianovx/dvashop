<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleMake;

class VehicleMakePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function view(User $user, VehicleMake $vehicleMake): bool
    {
        return $this->canManageCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function update(User $user, VehicleMake $vehicleMake): bool
    {
        return $this->canManageCatalog($user);
    }

    public function delete(User $user, VehicleMake $vehicleMake): bool
    {
        return $this->canManageCatalog($user);
    }

    public function restore(User $user, VehicleMake $vehicleMake): bool
    {
        return $this->canManageCatalog($user);
    }

    public function forceDelete(User $user, VehicleMake $vehicleMake): bool
    {
        return $user->isSuperAdmin();
    }

    private function canManageCatalog(User $user): bool
    {
        return $user->role?->canAccessAdminPanel() ?? false;
    }
}
