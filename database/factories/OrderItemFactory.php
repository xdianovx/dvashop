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
            'title_snapshot' => $variant->product->title.($variant->title ? ' — '.$variant->title : ''),
            'sku_snapshot' => $variant->sku ?: $variant->product->sku,
            'options_snapshot' => $variant->options,
            'image_snapshot' => '/img/placeholders/image.svg',
            'price_snapshot' => $price,
            'old_price_snapshot' => $variant->old_price,
            'total_snapshot' => round($price * $quantity, 2),
            'title' => $variant->product->title.($variant->title ? ' — '.$variant->title : ''),
            'sku' => $variant->sku ?: $variant->product->sku,
            'quantity' => $quantity,
            'stock_was_decremented' => false,
            'stock_restored_at' => null,
            'price' => $price,
            'total' => round($price * $quantity, 2),
        ];
    }
}
