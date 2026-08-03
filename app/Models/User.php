<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'blocked_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->canAccessAdminPanel();
    }

    public function canAccessAdminPanel(): bool
    {
        return $this->is_active
            && $this->blocked_at === null
            && ($this->resolvedRole()?->canAccessAdminPanel() ?? false);
    }

    public function isSuperAdmin(): bool
    {
        return $this->resolvedRole() === UserRole::SuperAdmin;
    }

    public function isActiveSuperAdmin(): bool
    {
        return $this->isSuperAdmin()
            && $this->is_active
            && $this->blocked_at === null;
    }

    public function adminStatusLabel(): string
    {
        return match (true) {
            $this->blocked_at !== null => 'Заблокирован',
            ! $this->is_active => 'Отключён',
            default => 'Активен',
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'blocked_at' => 'datetime',
        ];
    }

    private function resolvedRole(): ?UserRole
    {
        $role = $this->getRawOriginal('role') ?? ($this->getAttributes()['role'] ?? null);

        if ($role instanceof UserRole) {
            return $role;
        }

        return is_string($role) ? UserRole::tryFrom($role) : null;
    }
}
