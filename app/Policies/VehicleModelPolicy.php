<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleModel;

class VehicleModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function view(User $user, VehicleModel $vehicleModel): bool
    {
        return $this->canManageCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function update(User $user, VehicleModel $vehicleModel): bool
    {
        return $this->canManageCatalog($user);
    }

    public function delete(User $user, VehicleModel $vehicleModel): bool
    {
        return $this->canManageCatalog($user);
    }

    public function restore(User $user, VehicleModel $vehicleModel): bool
    {
        return $this->canManageCatalog($user);
    }

    public function forceDelete(User $user, VehicleModel $vehicleModel): bool
    {
        return $user->isSuperAdmin();
    }

    private function canManageCatalog(User $user): bool
    {
        return $user->role?->canAccessAdminPanel() ?? false;
    }
}
