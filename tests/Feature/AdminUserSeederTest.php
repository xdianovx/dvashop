<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('admin user seeder creates the initial active super admin without exposing its password', function (): void {
    Artisan::call('db:seed', [
        '--class' => AdminUserSeeder::class,
        '--force' => true,
    ]);

    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::SuperAdmin)
        ->and($user->is_active)->toBeTrue()
        ->and($user->blocked_at)->toBeNull()
        ->and($user->isActiveSuperAdmin())->toBeTrue()
        ->and(Artisan::output())->not->toContain('change-me');
});

test('repeated seeding never overwrites the active super admin', function (): void {
    $this->seed(AdminUserSeeder::class);

    $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $user->forceFill([
        'name' => 'Имя изменено вручную',
        'password' => 'manually-changed-password',
    ])->save();
    $passwordHash = $user->password;

    $this->seed(AdminUserSeeder::class);
    $user->refresh();

    expect($user->name)->toBe('Имя изменено вручную')
        ->and($user->role)->toBe(UserRole::SuperAdmin)
        ->and($user->is_active)->toBeTrue()
        ->and($user->blocked_at)->toBeNull()
        ->and($user->password)->toBe($passwordHash)
        ->and(Hash::check('manually-changed-password', $user->password))->toBeTrue();
});

test('occupied admin email is left unchanged when another active super admin exists', function (): void {
    $existing = User::factory()->manager()->inactive()->blocked()->create([
        'name' => 'Существующий пользователь',
        'email' => 'admin@example.com',
        'password' => 'existing-password',
    ]);
    User::factory()->superAdmin()->create();
    $original = $existing->only(['name', 'email', 'password', 'role', 'is_active', 'blocked_at']);

    $this->seed(AdminUserSeeder::class);

    expect($existing->refresh()->only(['name', 'email', 'password', 'role', 'is_active', 'blocked_at']))->toEqual($original)
        ->and(Hash::check('existing-password', $existing->password))->toBeTrue();
});

test('occupied admin email without an active super admin fails without changing or exposing credentials', function (): void {
    $existing = User::factory()->manager()->inactive()->blocked()->create([
        'name' => 'Существующий пользователь',
        'email' => 'admin@example.com',
        'password' => 'existing-secret-password',
    ]);
    $original = $existing->only(['name', 'email', 'password', 'role', 'is_active', 'blocked_at']);
    $exception = null;

    try {
        Artisan::call('db:seed', [
            '--class' => AdminUserSeeder::class,
            '--force' => true,
        ]);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception?->getMessage())->toContain('ADMIN_EMAIL')
        ->toContain('активный суперадминистратор отсутствует')
        ->toContain('Укажите свободный ADMIN_EMAIL')
        ->not->toContain('existing-secret-password')
        ->not->toContain('change-me')
        ->and(Artisan::output())->not->toContain('existing-secret-password')
        ->not->toContain('change-me')
        ->and($existing->refresh()->only(['name', 'email', 'password', 'role', 'is_active', 'blocked_at']))->toEqual($original)
        ->and(Hash::check('existing-secret-password', $existing->password))->toBeTrue();
});
