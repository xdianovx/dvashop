<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function userAdminUpdatePayload(User $user, array $overrides = []): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role->value,
        'is_active' => $user->is_active,
        ...$overrides,
    ];
}

function assertUserAdminValidationFailure(callable $operation): void
{
    try {
        $operation();
        test()->fail('Операция должна завершиться ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception)->toBeInstanceOf(ValidationException::class);
    }
}

test('service requires a matching password confirmation when creating a user', function (): void {
    $actor = User::factory()->superAdmin()->create();
    $service = app(UserAdminService::class);
    $payload = [
        'name' => 'Новый пользователь',
        'email' => 'new-user@example.com',
        'role' => UserRole::Manager->value,
        'is_active' => true,
        'password' => 'secure-password',
    ];

    assertUserAdminValidationFailure(fn () => $service->create($actor, $payload));
    assertUserAdminValidationFailure(fn () => $service->create($actor, [
        ...$payload,
        'password_confirmation' => 'different-password',
    ]));

    $user = $service->create($actor, [
        ...$payload,
        'password_confirmation' => 'secure-password',
    ]);

    expect(Hash::check('secure-password', $user->password))->toBeTrue()
        ->and($user->getAttributes())->not->toHaveKey('password_confirmation');
});

test('service requires confirmation for password updates and preserves the hash for an empty password', function (): void {
    $actor = User::factory()->superAdmin()->create();
    $target = User::factory()->manager()->create(['password' => 'old-password']);
    $service = app(UserAdminService::class);
    $oldHash = $target->password;

    assertUserAdminValidationFailure(fn () => $service->update($actor, $target, userAdminUpdatePayload($target, [
        'password' => 'new-secure-password',
    ])));
    assertUserAdminValidationFailure(fn () => $service->update($actor, $target, userAdminUpdatePayload($target, [
        'password' => 'new-secure-password',
        'password_confirmation' => 'different-password',
    ])));

    expect($target->refresh()->password)->toBe($oldHash);

    $service->update($actor, $target, userAdminUpdatePayload($target, [
        'password' => '',
        'password_confirmation' => '',
    ]));

    expect($target->refresh()->password)->toBe($oldHash);

    $service->update($actor, $target, userAdminUpdatePayload($target, [
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ]));

    expect($target->refresh()->password)->not->toBe($oldHash)
        ->and(Hash::check('new-secure-password', $target->password))->toBeTrue()
        ->and($target->getAttributes())->not->toHaveKey('password_confirmation');
});

test('a super admin cannot block deactivate demote or delete itself', function (): void {
    $user = User::factory()->superAdmin()->create();
    $service = app(UserAdminService::class);
    $original = $user->only(['role', 'is_active', 'blocked_at']);

    expect(fn () => $service->block($user, $user))->toThrow(ValidationException::class)
        ->and(fn () => $service->update($user, $user, userAdminUpdatePayload($user, ['is_active' => false])))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->update($user, $user, userAdminUpdatePayload($user, ['role' => UserRole::Admin->value])))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->delete($user, $user))->toThrow(ValidationException::class)
        ->and($user->refresh()->only(['role', 'is_active', 'blocked_at']))->toBe($original);
});

test('the last active super admin remains unchanged after every forbidden operation', function (): void {
    $user = User::factory()->superAdmin()->create();
    $service = app(UserAdminService::class);

    foreach ([
        fn () => $service->block($user, $user),
        fn () => $service->update($user, $user, userAdminUpdatePayload($user, ['is_active' => false])),
        fn () => $service->update($user, $user, userAdminUpdatePayload($user, ['role' => UserRole::Manager->value])),
        fn () => $service->delete($user, $user),
    ] as $operation) {
        try {
            $operation();
            test()->fail('Операция над последним активным суперадминистратором должна быть запрещена.');
        } catch (ValidationException) {
            expect($user->refresh()->isActiveSuperAdmin())->toBeTrue();
        }
    }
});

test('one super admin may change another when a second active super admin remains', function (): void {
    $actor = User::factory()->superAdmin()->create();
    $target = User::factory()->superAdmin()->create();
    $service = app(UserAdminService::class);

    $service->update($actor, $target, userAdminUpdatePayload($target, [
        'role' => UserRole::Manager->value,
    ]));

    expect($target->refresh()->role)->toBe(UserRole::Manager)
        ->and($actor->refresh()->isActiveSuperAdmin())->toBeTrue();
});

test('critical changes are transactionally locked and non super admins are rejected', function (): void {
    $manager = User::factory()->manager()->create();
    $target = User::factory()->create();
    $service = app(UserAdminService::class);

    expect(fn () => $service->block($manager, $target))->toThrow(ValidationException::class);

    $source = file_get_contents(app_path('Services/UserAdminService.php'));

    expect($source)->toContain('DB::transaction')
        ->toContain('lockForUpdate()')
        ->toContain("where('role', UserRole::SuperAdmin->value)");
});

test('physical deletion is forbidden even for a super admin', function (): void {
    $actor = User::factory()->superAdmin()->create();
    $target = User::factory()->create();
    $service = app(UserAdminService::class);

    expect(fn () => $service->delete($actor, $target))->toThrow(ValidationException::class)
        ->and($target->fresh())->not->toBeNull();
});

test('every non privileged actor is rejected by every service operation without changing targets', function (): void {
    $actors = [
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive super admin' => User::factory()->superAdmin()->inactive()->create(),
        'blocked super admin' => User::factory()->superAdmin()->blocked()->create(),
    ];
    $service = app(UserAdminService::class);
    $protectedAttributes = ['name', 'email', 'password', 'role', 'is_active', 'blocked_at'];

    foreach ($actors as $label => $actor) {
        $target = User::factory()->manager()->create();
        $original = $target->only($protectedAttributes);
        $userCount = User::query()->count();

        assertUserAdminValidationFailure(fn () => $service->create($actor, [
            'name' => 'Запрещённое создание',
            'email' => "forbidden-{$actor->getKey()}@example.com",
            'role' => UserRole::Manager->value,
            'is_active' => true,
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]));
        expect(User::query()->count(), $label)->toBe($userCount);

        foreach ([
            fn () => $service->update($actor, $target, userAdminUpdatePayload($target, ['name' => 'Изменено'])),
            fn () => $service->block($actor, $target),
            fn () => $service->unblock($actor, $target),
        ] as $operation) {
            assertUserAdminValidationFailure($operation);
            expect($target->refresh()->only($protectedAttributes), $label)->toEqual($original);
        }
    }
});
