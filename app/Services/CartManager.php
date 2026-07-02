<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

class CartManager
{
    public const COOKIE_NAME = 'cart_token';

    private const COOKIE_MINUTES = 60 * 24 * 60;

    private const CART_TTL_DAYS = 60;

    public function current(Request $request): Cart
    {
        $cart = $this->findActiveCart((string) $request->cookie(self::COOKIE_NAME));

        if (! $cart) {
            $cart = $this->createCart($request->user());
        }

        $this->queueCookie($cart);

        return $cart;
    }

    public function startNew(Request $request): Cart
    {
        $cart = $this->createCart($request->user());

        $this->queueCookie($cart);

        return $cart;
    }

    public function addItem(Request $request, int $productVariantId, int $quantity = 1): CartItem
    {
        $cart = $this->current($request);
        $variant = $this->findAvailableVariant($productVariantId);

        $item = $cart->items()->firstOrNew([
            'product_variant_id' => $variant->getKey(),
        ]);

        if ($item->exists) {
            $item->quantity += max(1, $quantity);
            $item->save();

            return $item->refresh();
        }

        $item->fill([
            'quantity' => max(1, $quantity),
            'price_snapshot' => $variant->price,
            'title_snapshot' => $this->titleSnapshot($variant),
        ]);

        $item->save();

        return $item->refresh();
    }

    public function updateQuantity(Request $request, CartItem $item, int $quantity): CartItem
    {
        $cart = $this->current($request);
        $this->ensureItemBelongsToCart($item, $cart);

        $item->update(['quantity' => max(1, $quantity)]);

        return $item->refresh();
    }

    public function removeItem(Request $request, CartItem $item): void
    {
        $cart = $this->current($request);
        $this->ensureItemBelongsToCart($item, $cart);

        $item->delete();
    }

    public function clear(Request $request): void
    {
        $this->current($request)->items()->delete();
    }

    /**
     * @return array{items_count: int, subtotal: float}
     */
    public function totals(Cart $cart): array
    {
        $items = $cart->items()->get(['quantity', 'price_snapshot']);

        return [
            'items_count' => (int) $items->sum('quantity'),
            'subtotal' => round((float) $items->sum(
                fn (CartItem $item): float => (float) $item->price_snapshot * $item->quantity
            ), 2),
        ];
    }

    private function findActiveCart(string $token): ?Cart
    {
        if ($token === '') {
            return null;
        }

        return Cart::query()
            ->active()
            ->where('token', $token)
            ->first();
    }

    private function createCart(?Authenticatable $user): Cart
    {
        return Cart::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'status' => CartStatus::Active,
            'expires_at' => now()->addDays(self::CART_TTL_DAYS),
        ]);
    }

    private function findAvailableVariant(int $productVariantId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->with('product')
            ->whereKey($productVariantId)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('status', ProductStatus::Active->value))
            ->first();

        if (! $variant) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Товар недоступен для добавления в корзину.',
            ]);
        }

        return $variant;
    }

    private function titleSnapshot(ProductVariant $variant): string
    {
        $parts = array_filter([
            $variant->product?->title,
            $variant->title,
        ]);

        return implode(' — ', $parts) ?: 'Товар';
    }

    private function ensureItemBelongsToCart(CartItem $item, Cart $cart): void
    {
        abort_unless((int) $item->cart_id === (int) $cart->getKey(), 404);
    }

    private function queueCookie(Cart $cart): void
    {
        Cookie::queue(cookie(
            self::COOKIE_NAME,
            $cart->token,
            self::COOKIE_MINUTES,
            null,
            null,
            null,
            true,
            false,
            'lax'
        ));
    }
}
