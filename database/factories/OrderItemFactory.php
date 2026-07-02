<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $variant = ProductVariant::factory()->create();
        $quantity = $this->faker->numberBetween(1, 5);
        $price = $this->faker->randomFloat(2, 1000, 10000);

        return [
            'order_id' => Order::factory(),
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'title' => $variant->product->title.($variant->title ? ' — '.$variant->title : ''),
            'sku' => $variant->sku ?: $variant->product->sku,
            'quantity' => $quantity,
            'price' => $price,
            'total' => round($price * $quantity, 2),
        ];
    }
}
