<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageOrders($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->canManageOrders($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return $this->canManageOrders($user);
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->isSuperAdmin();
    }

    private function canManageOrders(User $user): bool
    {
        return $user->role?->canAccessAdminPanel() ?? false;
    }
}
