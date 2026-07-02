<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'token' => (string) Str::uuid(),
            'user_id' => null,
            'status' => CartStatus::Active,
            'expires_at' => now()->addDays(60),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function ordered(): static
    {
        return $this->state(fn (): array => ['status' => CartStatus::Ordered]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (): array => ['status' => CartStatus::Abandoned]);
    }
}
