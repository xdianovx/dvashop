<?php

namespace Database\Factories;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
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
        $city = $this->faker->city();
        $address = $this->faker->streetAddress();

        return [
            'user_id' => null,
            'cart_id' => Cart::factory(),
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethod::Card,
            'delivery_method' => DeliveryMethod::Courier,
            'customer_name' => $this->faker->name(),
            'customer_phone' => '+79990000000',
            'customer_email' => $this->faker->safeEmail(),
            'customer_city' => $city,
            'customer_address' => $address,
            'customer_comment' => null,
            'delivery_city' => $city,
            'delivery_address' => $address,
            'comment' => null,
            'manager_comment' => null,
            'subtotal' => $subtotal,
            'delivery_price' => 0,
            'total' => $subtotal,
            'placed_at' => now(),
            'paid_at' => null,
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }
}
