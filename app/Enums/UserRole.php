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
            self::SuperAdmin => 'Суперадминистратор',
            self::Admin => 'Администратор',
            self::Manager => 'Менеджер',
            self::Customer => 'Покупатель',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }

    public function canAccessAdminPanel(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin, self::Manager], true);
    }
}
