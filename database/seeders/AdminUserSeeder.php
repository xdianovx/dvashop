<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        if (User::query()->where('email', $email)->exists()) {
            if (User::query()
                ->where('role', UserRole::SuperAdmin->value)
                ->where('is_active', true)
                ->whereNull('blocked_at')
                ->exists()) {
                return;
            }

            throw new RuntimeException(
                "Пользователь с ADMIN_EMAIL {$email} уже существует, но активный суперадминистратор отсутствует. "
                .'Укажите свободный ADMIN_EMAIL либо восстановите суперадминистратора вручную.'
            );
        }

        User::query()->create([
            'name' => env('ADMIN_NAME', 'DvaShop Super Admin'),
            'email' => $email,
            'password' => env('ADMIN_PASSWORD', 'change-me'),
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
            'blocked_at' => null,
            'email_verified_at' => now(),
        ]);
    }
}
