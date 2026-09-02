<?php

namespace Database\Factories;

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
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
            'promo_code_id' => null,
            'promo_code_snapshot' => null,
            'promo_name_snapshot' => null,
            'promo_discount_type_snapshot' => null,
            'promo_discount_value_snapshot' => null,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => PaymentMethod::Card,
            'payment_method_title_snapshot' => PaymentMethod::Card->label(),
            'payment_method_description_snapshot' => null,
            'delivery_method' => DeliveryMethod::Courier,
            'delivery_method_title_snapshot' => DeliveryMethod::Courier->label(),
            'delivery_method_description_snapshot' => null,
            'delivery_price_mode_snapshot' => DeliveryPriceMode::Free,
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
            'discount_total' => 0,
            'delivery_price' => 0,
            'total' => $subtotal,
            'total_is_final' => 1,
            'placed_at' => now(),
            'paid_at' => null,
            'customer_email_sent_at' => null,
            'manager_email_sent_at' => null,
            'bitrix_sent_at' => null,
            'bitrix_entity_id' => null,
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }
}
