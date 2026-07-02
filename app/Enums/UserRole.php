<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Customer => 'Customer',
        };
    }

    public function canAccessAdminPanel(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin, self::Manager], true);
    }
}
