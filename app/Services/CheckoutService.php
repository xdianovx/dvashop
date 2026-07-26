<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(private readonly CartManager $cartManager) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOrderFromCart(Request $request, array $data): Order
    {
        $validated = $this->validate($data);
        $cart = $this->cartManager->current($request);

        $order = DB::transaction(function () use ($cart, $request, $validated): Order {
            /** @var Cart $lockedCart */
            $lockedCart = Cart::query()
                ->with('items')
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCart->status !== CartStatus::Active || $lockedCart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'Нельзя оформить пустую корзину.',
                ]);
            }

            $this->validateCartItems($lockedCart);
            $subtotal = $this->subtotal($lockedCart);
            $deliveryPrice = 0.0;

            $order = Order::query()->create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'cart_id' => $lockedCart->getKey(),
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method' => $validated['payment_method'],
                'delivery_method' => $validated['delivery_method'],
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_email' => $validated['customer_email'] ?? null,
                'customer_city' => $validated['customer_city'] ?? null,
                'customer_address' => $validated['customer_address'] ?? null,
                'customer_comment' => $validated['customer_comment'] ?? null,
                // Legacy fields remain synchronized until the public checkout is connected.
                'delivery_city' => $validated['customer_city'] ?? null,
                'delivery_address' => $validated['customer_address'] ?? null,
                'comment' => $validated['customer_comment'] ?? null,
                'subtotal' => $subtotal,
                'delivery_price' => $deliveryPrice,
                'total' => round($subtotal + $deliveryPrice, 2),
                'placed_at' => now(),
            ]);

            foreach ($lockedCart->items as $item) {
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'title_snapshot' => $item->title_snapshot,
                    'sku_snapshot' => $item->sku_snapshot,
                    'options_snapshot' => ProductVariant::optionsWithoutManagementMetadata($item->options_snapshot),
                    'image_snapshot' => $item->image_snapshot,
                    'price_snapshot' => $item->price_snapshot,
                    'old_price_snapshot' => $item->old_price_snapshot,
                    'total_snapshot' => $item->lineTotal(),
                    // Legacy fields are retained for backward compatibility.
                    'title' => $item->title_snapshot,
                    'sku' => $item->sku_snapshot,
                    'quantity' => $item->quantity,
                    'price' => $item->price_snapshot,
                    'total' => $item->lineTotal(),
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
            fn (CartItem $item): float => $item->lineTotal()
        ), 2);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $payload = [
            ...$data,
            'customer_city' => $data['customer_city'] ?? $data['delivery_city'] ?? null,
            'customer_address' => $data['customer_address'] ?? $data['delivery_address'] ?? null,
            'customer_comment' => $data['customer_comment'] ?? $data['comment'] ?? null,
            'delivery_method' => $data['delivery_method'] ?? DeliveryMethod::Courier->value,
            'payment_method' => $data['payment_method'] ?? PaymentMethod::Card->value,
        ];

        return Validator::make($payload, [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_city' => ['nullable', 'string', 'max:255'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'customer_comment' => ['nullable', 'string', 'max:5000'],
            'delivery_method' => ['required', Rule::enum(DeliveryMethod::class)],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'agree_terms' => ['required', 'accepted'],
        ], [
            'agree_terms.accepted' => 'Необходимо согласие на обработку персональных данных.',
            'agree_terms.required' => 'Необходимо согласие на обработку персональных данных.',
        ])->validate();
    }

    private function validateCartItems(Cart $cart): void
    {
        foreach ($cart->items as $item) {
            if ($item->quantity <= 0) {
                throw ValidationException::withMessages([
                    'cart' => 'Количество товара в корзине должно быть больше нуля.',
                ]);
            }

            if (blank($item->title_snapshot) || $item->price_snapshot === null) {
                throw ValidationException::withMessages([
                    'cart' => 'В корзине есть товар без обязательного снимка названия или цены.',
                ]);
            }
        }
    }
}
