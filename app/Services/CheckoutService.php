<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(private readonly CartManager $cartManager)
    {
    }

    /**
     * @param array{customer_name: string, customer_phone: string, customer_email?: ?string, delivery_city?: ?string, delivery_address?: ?string, comment?: ?string} $data
     */
    public function createOrderFromCart(Request $request, array $data): Order
    {
        $cart = $this->cartManager->current($request);

        $order = DB::transaction(function () use ($cart, $request, $data): Order {
            /** @var Cart $lockedCart */
            $lockedCart = Cart::query()
                ->with(['items.variant.product'])
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCart->status !== CartStatus::Active || $lockedCart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'Нельзя оформить пустую корзину.',
                ]);
            }

            $subtotal = $this->subtotal($lockedCart);

            $order = Order::query()->create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'cart_id' => $lockedCart->getKey(),
                'status' => OrderStatus::New,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'delivery_city' => $data['delivery_city'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'comment' => $data['comment'] ?? null,
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            foreach ($lockedCart->items as $item) {
                $variant = $item->variant;
                $product = $variant?->product;

                if (! $variant || ! $product) {
                    continue;
                }

                $order->items()->create([
                    'product_id' => $product->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'title' => $item->title_snapshot,
                    'sku' => $variant->sku ?: $product->sku,
                    'quantity' => $item->quantity,
                    'price' => $item->price_snapshot,
                    'total' => $this->lineTotal($item),
                ]);
            }

            $lockedCart->update(['status' => CartStatus::Ordered]);

            return $order->load('items');
        });

        $this->cartManager->startNew($request);

        OrderCreated::dispatch($order);

        return $order;
    }

    private function subtotal(Cart $cart): float
    {
        return round((float) $cart->items->sum(
            fn (CartItem $item): float => $this->lineTotal($item)
        ), 2);
    }

    private function lineTotal(CartItem $item): float
    {
        return round((float) $item->price_snapshot * $item->quantity, 2);
    }
}
