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
        return [
            'cart_id' => Cart::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'price_snapshot' => fake()->randomFloat(2, 1000, 50000),
            'title_snapshot' => fake()->words(4, true),
        ];
    }

    public function forCart(Cart $cart): static
    {
        return $this->state(fn (): array => ['cart_id' => $cart->getKey()]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (): array => [
            'product_variant_id' => $variant->getKey(),
            'price_snapshot' => $variant->price,
            'title_snapshot' => $variant->product->title,
        ]);
    }
}
