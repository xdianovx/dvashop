<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->superAdmin = User::factory()->superAdmin()->create();
    $this->actingAs($this->superAdmin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('user resource is registered with russian system navigation labels', function (): void {
    expect(Filament::getPanel('admin')->getResources())->toContain(UserResource::class)
        ->and(UserResource::getNavigationGroup())->toBe('Система')
        ->and(UserResource::getNavigationLabel())->toBe('Пользователи')
        ->and(UserResource::getModelLabel())->toBe('пользователь')
        ->and(UserResource::getPluralModelLabel())->toBe('Пользователи');
});

test('super admin can open list create and edit pages', function (): void {
    $target = User::factory()->create();

    $this->get(UserResource::getUrl('index'))->assertOk();
    $this->get(UserResource::getUrl('create'))->assertOk();
    $this->get(UserResource::getUrl('edit', ['record' => $target]))->assertOk();
});

test('user table supports search role filter status filter and required columns', function (): void {
    $manager = User::factory()->manager()->create([
        'name' => 'Уникальный Менеджер',
        'email' => 'unique-manager@example.com',
    ]);
    $admin = User::factory()->admin()->inactive()->create();
    $blocked = User::factory()->blocked()->create();

    Livewire::test(ListUsers::class)
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('email')
        ->assertTableColumnExists('role')
        ->assertTableColumnExists('admin_status')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at')
        ->searchTable('Уникальный Менеджер')
        ->assertCanSeeTableRecords([$manager])
        ->assertCanNotSeeTableRecords([$admin, $blocked])
        ->searchTable('unique-manager@example.com')
        ->assertCanSeeTableRecords([$manager])
        ->filterTable('role', UserRole::Manager->value)
        ->assertCanSeeTableRecords([$manager])
        ->assertCanNotSeeTableRecords([$admin, $blocked]);

    Livewire::test(ListUsers::class)
        ->filterTable('admin_status', 'inactive')
        ->assertCanSeeTableRecords([$admin])
        ->assertCanNotSeeTableRecords([$manager, $blocked]);

    Livewire::test(ListUsers::class)
        ->filterTable('admin_status', 'blocked')
        ->assertCanSeeTableRecords([$blocked])
        ->assertCanNotSeeTableRecords([$manager, $admin]);
});

test('super admin creates a user with a once hashed confirmed password', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Новый администратор',
            'email' => 'new-admin@example.com',
            'role' => UserRole::Admin->value,
            'is_active' => true,
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'new-admin@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->is_active)->toBeTrue()
        ->and($user->blocked_at)->toBeNull()
        ->and($user->password)->not->toBe('secure-password')
        ->and(Hash::check('secure-password', $user->password))->toBeTrue();
});

test('create form requires matching passwords', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Пользователь',
            'email' => 'password-check@example.com',
            'role' => UserRole::Manager->value,
            'is_active' => true,
            'password' => 'secure-password',
            'password_confirmation' => 'different-password',
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => 'confirmed']);

    $this->assertDatabaseMissing('users', ['email' => 'password-check@example.com']);
});

test('super admin edits another user role and active status', function (): void {
    $target = User::factory()->manager()->create();

    Livewire::test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => 'Обновлённый пользователь',
            'email' => 'updated-user@example.com',
            'role' => UserRole::Admin->value,
            'is_active' => false,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $target->refresh();

    expect($target->name)->toBe('Обновлённый пользователь')
        ->and($target->email)->toBe('updated-user@example.com')
        ->and($target->role)->toBe(UserRole::Admin)
        ->and($target->is_active)->toBeFalse();
});

test('empty password keeps the hash and a confirmed new password replaces it', function (): void {
    $target = User::factory()->manager()->create(['password' => 'old-password']);
    $oldHash = $target->password;

    Livewire::test(EditUser::class, ['record' => $target->getKey()])
        ->assertSet('data.password', null)
        ->assertDontSee($oldHash)
        ->fillForm([
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role->value,
            'is_active' => true,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->password)->toBe($oldHash);

    Livewire::test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role->value,
            'is_active' => true,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $target->refresh();

    expect($target->password)->not->toBe($oldHash)
        ->and(Hash::check('new-secure-password', $target->password))->toBeTrue();
});

test('table actions block and unblock another user', function (): void {
    $target = User::factory()->manager()->create();

    Livewire::test(ListUsers::class)
        ->callTableAction('block', $target)
        ->assertNotified('Пользователь заблокирован');

    expect($target->refresh()->blocked_at)->not->toBeNull();

    Livewire::test(ListUsers::class)
        ->callTableAction('unblock', $target)
        ->assertNotified('Пользователь разблокирован');

    expect($target->refresh()->blocked_at)->toBeNull();
});

test('self blocking is rejected without an exception page or data change', function (): void {
    Livewire::test(ListUsers::class)
        ->callTableAction('block', $this->superAdmin)
        ->assertNotified('Нельзя заблокировать собственную учётную запись.');

    expect($this->superAdmin->refresh()->blocked_at)->toBeNull();
});

test('self deactivation and demotion return form errors without changing the account', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->superAdmin->getKey()])
        ->fillForm([
            'name' => $this->superAdmin->name,
            'email' => $this->superAdmin->email,
            'role' => UserRole::SuperAdmin->value,
            'is_active' => false,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($this->superAdmin->refresh()->isActiveSuperAdmin())->toBeTrue();

    Livewire::test(EditUser::class, ['record' => $this->superAdmin->getKey()])
        ->fillForm([
            'name' => $this->superAdmin->name,
            'email' => $this->superAdmin->email,
            'role' => UserRole::Admin->value,
            'is_active' => true,
            'password' => '',
            'password_confirmation' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['role']);

    expect($this->superAdmin->refresh()->isActiveSuperAdmin())->toBeTrue();
});

test('delete actions and sensitive fields are absent', function (): void {
    $target = User::factory()->create();
    $resourceSource = file_get_contents(app_path('Filament/Resources/Users/UserResource.php'));
    $editSource = file_get_contents(app_path('Filament/Resources/Users/Pages/EditUser.php'));

    Livewire::test(ListUsers::class)
        ->assertTableActionDoesNotExist('delete', record: $target);

    expect($resourceSource)->not->toContain('DeleteAction')
        ->not->toContain('DeleteBulkAction')
        ->not->toContain('ForceDeleteAction')
        ->not->toContain('RestoreAction')
        ->not->toContain('remember_token')
        ->and($editSource)->not->toContain('DeleteAction')
        ->and($this->superAdmin->can('delete', $target))->toBeFalse();
});
