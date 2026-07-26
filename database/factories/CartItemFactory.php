<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition(): array
    {
        $variant = ProductVariant::factory()->create();

        return [
            'cart_id' => Cart::factory(),
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'quantity' => fake()->numberBetween(1, 5),
            'sku_snapshot' => $variant->sku ?: $variant->product->sku,
            'price_snapshot' => fake()->randomFloat(2, 1000, 50000),
            'old_price_snapshot' => null,
            'title_snapshot' => fake()->words(4, true),
            'options_snapshot' => $variant->options,
            'image_snapshot' => '/img/placeholders/image.svg',
        ];
    }

    public function forCart(Cart $cart): static
    {
        return $this->state(fn (): array => ['cart_id' => $cart->getKey()]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (): array => [
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'sku_snapshot' => $variant->sku ?: $variant->product->sku,
            'price_snapshot' => $variant->price,
            'old_price_snapshot' => $variant->old_price,
            'title_snapshot' => $variant->product->title,
            'options_snapshot' => $variant->options,
        ]);
    }
}
