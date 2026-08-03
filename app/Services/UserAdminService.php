<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserAdminService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): User
    {
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ])->validate();

        unset($data['password_confirmation']);

        return DB::transaction(function () use ($actor, $data): User {
            $lockedActor = User::query()
                ->whereKey($actor->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedActor instanceof User) {
                $this->deny('user', 'Пользователь больше не существует.');
            }

            $this->ensureActorCanManageUsers($lockedActor);

            return User::query()->create([
                ...$data,
                'blocked_at' => null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, User $target, array $attributes): User
    {
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['nullable', 'required_with:password', 'string'],
        ])->validate();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        unset($data['password_confirmation']);

        return $this->change($actor, $target, $data);
    }

    public function block(User $actor, User $target): User
    {
        return $this->change($actor, $target, ['blocked_at' => now()]);
    }

    public function unblock(User $actor, User $target): User
    {
        return $this->change($actor, $target, ['blocked_at' => null]);
    }

    public function delete(User $actor, User $target): never
    {
        $this->ensureActorCanManageUsers($actor);

        throw ValidationException::withMessages([
            'user' => $actor->is($target)
                ? 'Нельзя удалить собственную учётную запись.'
                : 'Удаление пользователей запрещено. Отключите или заблокируйте учётную запись.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function change(User $actor, User $target, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $target, $attributes): User {
            $users = User::query()
                ->whereKey([$actor->getKey(), $target->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user): int => (int) $user->getKey());

            /** @var User|null $lockedActor */
            $lockedActor = $users->get((int) $actor->getKey());
            /** @var User|null $lockedTarget */
            $lockedTarget = $users->get((int) $target->getKey());

            if (! $lockedActor instanceof User || ! $lockedTarget instanceof User) {
                throw ValidationException::withMessages([
                    'user' => 'Пользователь больше не существует.',
                ]);
            }

            $this->ensureActorCanManageUsers($lockedActor);
            $this->guardCriticalChange($lockedActor, $lockedTarget, $attributes);

            $lockedTarget->fill($attributes)->save();

            return $lockedTarget->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guardCriticalChange(User $actor, User $target, array $attributes): void
    {
        $nextRole = array_key_exists('role', $attributes)
            ? $this->roleFrom($attributes['role'])
            : ($target->isSuperAdmin() ? UserRole::SuperAdmin : $target->role);
        $willBeActive = (bool) ($attributes['is_active'] ?? $target->is_active);
        $willBeBlocked = array_key_exists('blocked_at', $attributes)
            ? $attributes['blocked_at'] !== null
            : $target->blocked_at !== null;

        if ($actor->is($target)) {
            if ($willBeBlocked) {
                $this->deny('blocked_at', 'Нельзя заблокировать собственную учётную запись.');
            }

            if (! $willBeActive) {
                $this->deny('is_active', 'Нельзя отключить собственную учётную запись.');
            }

            if ($target->isSuperAdmin() && $nextRole !== UserRole::SuperAdmin) {
                $this->deny('role', 'Нельзя понизить собственную роль суперадминистратора.');
            }
        }

        $removesActiveSuperAdmin = $target->isActiveSuperAdmin()
            && ($nextRole !== UserRole::SuperAdmin || ! $willBeActive || $willBeBlocked);

        if (! $removesActiveSuperAdmin) {
            return;
        }

        $activeSuperAdminIds = User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('is_active', true)
            ->whereNull('blocked_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeSuperAdminIds->count() <= 1) {
            $this->deny('role', 'Нельзя изменить последнего активного суперадминистратора.');
        }
    }

    private function ensureActorCanManageUsers(User $actor): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->canAccessAdminPanel()) {
            $this->deny('user', 'У вас нет прав для управления пользователями.');
        }
    }

    private function roleFrom(mixed $role): ?UserRole
    {
        if ($role instanceof UserRole) {
            return $role;
        }

        return is_string($role) ? UserRole::tryFrom($role) : null;
    }

    private function deny(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
