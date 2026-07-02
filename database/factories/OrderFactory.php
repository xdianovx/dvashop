<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 1000, 10000);

        return [
            'user_id' => null,
            'cart_id' => Cart::factory(),
            'status' => OrderStatus::New,
            'customer_name' => $this->faker->name(),
            'customer_phone' => '+79990000000',
            'customer_email' => $this->faker->safeEmail(),
            'delivery_city' => $this->faker->city(),
            'delivery_address' => $this->faker->streetAddress(),
            'comment' => null,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }
}
