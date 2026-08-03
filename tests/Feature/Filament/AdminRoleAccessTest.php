<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserAdminService;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('role labels are russian and database values remain stable', function (): void {
    expect(UserRole::SuperAdmin->value)->toBe('super_admin')
        ->and(UserRole::SuperAdmin->label())->toBe('Суперадминистратор')
        ->and(UserRole::Admin->label())->toBe('Администратор')
        ->and(UserRole::Manager->label())->toBe('Менеджер')
        ->and(UserRole::Customer->label())->toBe('Покупатель');
});

test('panel access requires an allowed role active status and no block', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->manager()->create();
    $customer = User::factory()->create();
    $inactive = User::factory()->admin()->inactive()->create();
    $blocked = User::factory()->manager()->blocked()->create();
    $panel = Filament::getPanel('admin');

    expect($superAdmin->canAccessPanel($panel))->toBeTrue()
        ->and($admin->canAccessPanel($panel))->toBeTrue()
        ->and($manager->canAccessPanel($panel))->toBeTrue()
        ->and($customer->canAccessPanel($panel))->toBeFalse()
        ->and($inactive->canAccessPanel($panel))->toBeFalse()
        ->and($blocked->canAccessPanel($panel))->toBeFalse();

    DB::table('users')->where('id', $manager->getKey())->update(['role' => 'invalid-role']);

    expect($manager->refresh()->canAccessPanel($panel))->toBeFalse();
});

test('filament login accepts only active unblocked administrative roles', function (): void {
    foreach ([
        User::factory()->superAdmin()->create(['password' => 'password']),
        User::factory()->admin()->create(['password' => 'password']),
        User::factory()->manager()->create(['password' => 'password']),
    ] as $user) {
        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        expect(Auth::id())->toBe($user->getKey());
        Auth::logout();
    }
});

test('filament login uses the same generic error for forbidden and invalid credentials', function (): void {
    $customer = User::factory()->create(['password' => 'password']);
    $inactive = User::factory()->admin()->inactive()->create(['password' => 'password']);
    $blocked = User::factory()->manager()->blocked()->create(['password' => 'password']);
    $admin = User::factory()->admin()->create(['password' => 'password']);
    $messages = [];

    foreach ([
        [$customer->email, 'password'],
        [$inactive->email, 'password'],
        [$blocked->email, 'password'],
        [$admin->email, 'wrong-password'],
        ['missing@example.com', 'wrong-password'],
    ] as [$email, $password]) {
        $component = Livewire::test(Login::class)
            ->fillForm(['email' => $email, 'password' => $password])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $messages[] = $component->errors()->first('data.email');
        Auth::logout();
    }

    expect(array_unique($messages))->toHaveCount(1);
});

test('only super admins can discover and open user resource pages', function (): void {
    $target = User::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)->get(UserResource::getUrl('index'))->assertOk();
    $this->actingAs($superAdmin)->get(UserResource::getUrl('create'))->assertOk();
    $this->actingAs($superAdmin)->get(UserResource::getUrl('edit', ['record' => $target]))->assertOk();

    foreach ([
        User::factory()->admin()->create(),
        User::factory()->manager()->create(),
        User::factory()->create(),
    ] as $forbiddenUser) {
        $this->actingAs($forbiddenUser)->get(UserResource::getUrl('index'))->assertForbidden();
        $this->actingAs($forbiddenUser)->get(UserResource::getUrl('create'))->assertForbidden();
        $this->actingAs($forbiddenUser)->get(UserResource::getUrl('edit', ['record' => $target]))->assertForbidden();
    }
});

test('a previously authenticated blocked manager loses panel access on the next request', function (): void {
    $manager = User::factory()->manager()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($manager)->get('/admin')->assertOk();

    app(UserAdminService::class)->block($superAdmin, $manager);
    Auth::forgetGuards();

    expect($this->get('/admin')->getStatusCode())->not->toBe(200)
        ->and($this->get(UserResource::getUrl('index'))->getStatusCode())->not->toBe(200);
});

test('a previously authenticated inactive admin loses panel access on the next request', function (): void {
    $admin = User::factory()->admin()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get('/admin')->assertOk();

    app(UserAdminService::class)->update($superAdmin, $admin, [
        'name' => $admin->name,
        'email' => $admin->email,
        'role' => $admin->role->value,
        'is_active' => false,
    ]);
    Auth::forgetGuards();

    expect($this->get('/admin')->getStatusCode())->not->toBe(200);
});

test('super admin sees the user navigation item', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Пользователи');
});

test('admin does not see the user navigation item', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('Пользователи');
});

test('manager does not see the user navigation item', function (): void {
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('Пользователи');
});

test('customer cannot access the panel or see the user navigation item', function (): void {
    $customer = User::factory()->create();
    $response = $this->actingAs($customer)->get('/admin');

    expect($response->getStatusCode())->not->toBe(200);
    $response->assertDontSee('Пользователи');
});
