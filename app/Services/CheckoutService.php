<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockStatus;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Services\Promotions\PromoCodePricingResult;
use App\Services\Promotions\PromoCodePricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly CartManager $cartManager,
        private readonly StorefrontProductAvailability $availability,
        private readonly PromoCodePricingService $promoPricing,
    ) {}

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
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $promo = null;
            $activePromoUsageCount = null;

            if ($lockedCart->promo_code_id !== null) {
                $promo = PromoCode::withTrashed()
                    ->whereKey($lockedCart->promo_code_id)
                    ->lockForUpdate()
                    ->first();

                if (! $promo instanceof PromoCode) {
                    throw ValidationException::withMessages([
                        'promo_code' => 'Промокод больше не действует. Проверьте сумму заказа.',
                    ]);
                }

                if ($promo->usage_limit !== null) {
                    $activePromoUsageCount = $promo->activeRedemptions()
                        ->lockForUpdate()
                        ->get(['id'])
                        ->count();
                }
            }

            $lockedCart->load(['items' => fn ($query) => $query->orderBy('id')]);

            if ($lockedCart->status !== CartStatus::Active || $lockedCart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'Нельзя оформить пустую корзину.',
                ]);
            }

            $this->validateCartItems($lockedCart);
            $deliverySetting = DeliveryMethodSetting::query()
                ->active()
                ->where('code', $validated['delivery_method'])
                ->lockForUpdate()
                ->first();
            $paymentSetting = PaymentMethodSetting::query()
                ->active()
                ->where('code', $validated['payment_method'])
                ->lockForUpdate()
                ->first();

            if (! $deliverySetting instanceof DeliveryMethodSetting) {
                throw ValidationException::withMessages([
                    'delivery_method' => 'Выбранный способ доставки недоступен.',
                ]);
            }
            if (! $paymentSetting instanceof PaymentMethodSetting) {
                throw ValidationException::withMessages([
                    'payment_method' => 'Выбранный способ оплаты недоступен.',
                ]);
            }

            $subtotal = $this->subtotal($lockedCart);
            $promoPricing = null;

            if ($promo instanceof PromoCode) {
                $promoPricing = $this->promoPricing->calculate(
                    $promo,
                    $lockedCart->items,
                    $activePromoUsageCount,
                );

                if (! $promoPricing->valid) {
                    throw ValidationException::withMessages([
                        'promo_code' => 'Промокод больше не действует. Проверьте сумму заказа.',
                    ]);
                }
            }

            $discountTotal = $promoPricing?->discountAmount() ?? 0.0;
            $deliveryPrice = $deliverySetting->price_mode === DeliveryPriceMode::Fixed
                ? round((float) $deliverySetting->base_price, 2)
                : 0.0;
            $totalIsFinal = $deliverySetting->price_mode !== DeliveryPriceMode::OnRequest;
            $stockDecrementedByCartItem = $this->reserveFiniteStock($lockedCart);

            $order = Order::query()->create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'cart_id' => $lockedCart->getKey(),
                'promo_code_id' => $promo?->getKey(),
                'promo_code_snapshot' => $promo?->code,
                'promo_name_snapshot' => $promo?->name,
                'promo_discount_type_snapshot' => $promo?->discount_type->value,
                'promo_discount_value_snapshot' => $promo?->discount_value,
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method' => $validated['payment_method'],
                'payment_method_title_snapshot' => $paymentSetting->title,
                'payment_method_description_snapshot' => $paymentSetting->description,
                'delivery_method' => $validated['delivery_method'],
                'delivery_method_title_snapshot' => $deliverySetting->title,
                'delivery_method_description_snapshot' => $deliverySetting->description,
                'delivery_price_mode_snapshot' => $deliverySetting->price_mode,
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
                'discount_total' => $discountTotal,
                'delivery_price' => $deliveryPrice,
                'total' => round(max(0, $subtotal - $discountTotal) + $deliveryPrice, 2),
                'total_is_final' => $totalIsFinal,
                'placed_at' => now(),
            ]);

            foreach ($lockedCart->items as $item) {
                $lineDiscount = $promoPricing instanceof PromoCodePricingResult
                    ? $promoPricing->lineDiscountAmount((int) $item->getKey())
                    : 0.0;
                $lineTotal = $item->lineTotal();

                $order->items()->create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'title_snapshot' => $item->title_snapshot,
                    'sku_snapshot' => $item->sku_snapshot,
                    'options_snapshot' => ProductVariant::optionsWithoutManagementMetadata($item->options_snapshot),
                    'image_snapshot' => $item->image_snapshot,
                    'price_snapshot' => $item->price_snapshot,
                    'old_price_snapshot' => $item->old_price_snapshot,
                    'total_snapshot' => $lineTotal,
                    'discount_snapshot' => $lineDiscount,
                    'final_total_snapshot' => round(max(0, $lineTotal - $lineDiscount), 2),
                    // Legacy fields are retained for backward compatibility.
                    'title' => $item->title_snapshot,
                    'sku' => $item->sku_snapshot,
                    'quantity' => $item->quantity,
                    'stock_was_decremented' => $stockDecrementedByCartItem[$item->getKey()] ?? false,
                    'price' => $item->price_snapshot,
                    'total' => $lineTotal,
                ]);
            }

            if ($promo instanceof PromoCode && $promoPricing instanceof PromoCodePricingResult) {
                PromoCodeRedemption::query()->create([
                    'promo_code_id' => $promo->getKey(),
                    'order_id' => $order->getKey(),
                    'discount_amount' => $promoPricing->discountAmount(),
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
        ];

        return Validator::make($payload, [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_city' => ['required', 'string', 'max:255'],
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
        $variantIds = $cart->items
            ->pluck('product_variant_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $existingVariants = ProductVariant::query()
            ->with(['product.category', 'product.partType', 'optionValues.group'])
            ->whereKey($variantIds->all())
            ->get()
            ->keyBy(fn (ProductVariant $variant): int => (int) $variant->getKey());

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

            if ((float) $item->price_snapshot <= 0) {
                throw ValidationException::withMessages([
                    'cart' => CartManager::PRICE_UNAVAILABLE_MESSAGE,
                ]);
            }

            if ($item->product_variant_id !== null) {
                $variant = $existingVariants->get((int) $item->product_variant_id);

                if ($variant instanceof ProductVariant && ! $this->availability->isPubliclyAvailable($variant)) {
                    throw ValidationException::withMessages([
                        'cart' => 'Один из товаров в корзине больше недоступен. Обновите корзину.',
                    ]);
                }
            }
        }
    }

    /** @return array<int, true> */
    private function reserveFiniteStock(Cart $cart): array
    {
        $itemsByVariant = $cart->items
            ->filter(fn (CartItem $item): bool => $item->product_variant_id !== null)
            ->groupBy(fn (CartItem $item): int => (int) $item->product_variant_id);

        if ($itemsByVariant->isEmpty()) {
            return [];
        }

        $variantIds = $itemsByVariant->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values();
        $variants = ProductVariant::query()
            ->whereKey($variantIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ProductVariant $variant): int => (int) $variant->getKey());
        $decremented = [];

        foreach ($variantIds as $variantId) {
            $variant = $variants->get($variantId);

            // A deleted catalog row is intentionally allowed by the historical snapshot checkout contract.
            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $items = $itemsByVariant->get($variantId, collect());
            $quantity = (int) $items->sum('quantity');

            if ($variant->stock_status === StockStatus::OutOfStock) {
                throw ValidationException::withMessages([
                    'cart' => 'Один из товаров в корзине закончился. Обновите корзину.',
                ]);
            }

            if ($variant->stock_status === StockStatus::PreOrder || $variant->stock_quantity === null) {
                continue;
            }

            if ((int) $variant->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'cart' => 'Запрошенное количество одного из товаров сейчас недоступно.',
                ]);
            }

            $updated = ProductVariant::query()
                ->whereKey($variantId)
                ->where('stock_quantity', '>=', $quantity)
                ->decrement('stock_quantity', $quantity);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'cart' => 'Остаток товара изменился. Обновите корзину и повторите заказ.',
                ]);
            }

            foreach ($items as $item) {
                $decremented[(int) $item->getKey()] = true;
            }
        }

        return $decremented;
    }
}
