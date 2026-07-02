<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleGeneration;

class VehicleGenerationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function view(User $user, VehicleGeneration $vehicleGeneration): bool
    {
        return $this->canManageCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function update(User $user, VehicleGeneration $vehicleGeneration): bool
    {
        return $this->canManageCatalog($user);
    }

    public function delete(User $user, VehicleGeneration $vehicleGeneration): bool
    {
        return $this->canManageCatalog($user);
    }

    public function restore(User $user, VehicleGeneration $vehicleGeneration): bool
    {
        return $this->canManageCatalog($user);
    }

    public function forceDelete(User $user, VehicleGeneration $vehicleGeneration): bool
    {
        return $user->isSuperAdmin();
    }

    private function canManageCatalog(User $user): bool
    {
        return $user->role?->canAccessAdminPanel() ?? false;
    }
}
