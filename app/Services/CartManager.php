<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Services\Promotions\PromoCodePricingService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartManager
{
    public const COOKIE_NAME = 'cart_token';

    public const PRICE_UNAVAILABLE_MESSAGE = 'Цена товара пока не указана. Оставьте заявку для уточнения стоимости.';

    private const COOKIE_MINUTES = 60 * 24 * 60;

    private const CART_TTL_DAYS = 60;

    public function __construct(
        private readonly StorefrontProductAvailability $availability,
        private readonly PromoCodePricingService $promoPricing,
    ) {}

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

    /**
     * Read the current cart totals without creating a cart or refreshing its cookie.
     *
     * @return array{items_count: int, subtotal: float}
     */
    public function summaryForRequest(Request $request): array
    {
        $cart = $this->findActiveCart((string) $request->cookie(self::COOKIE_NAME));

        return $cart ? $this->totals($cart) : $this->emptySummary();
    }

    public function addItem(Request $request, int $productVariantId, int $quantity = 1): CartItem
    {
        $cart = $this->current($request);
        $variant = $this->findAvailableVariant($productVariantId);
        $this->assertSellablePrice($variant);

        $item = $cart->items()->firstOrNew([
            'product_variant_id' => $variant->getKey(),
        ]);

        if ($item->exists) {
            $item->quantity += max(1, $quantity);
            $this->assertAvailableQuantity($variant, $item->quantity);
            $item->save();

            return $item->refresh();
        }

        $item->quantity = max(1, $quantity);
        $this->assertAvailableQuantity($variant, $item->quantity);
        $item->refreshSnapshotFromVariant($variant);

        $item->save();

        return $item->refresh();
    }

    public function updateQuantity(Request $request, CartItem $item, int $quantity): CartItem
    {
        $cart = $this->current($request);
        $this->ensureItemBelongsToCart($item, $cart);
        $variant = $this->findAvailableVariant((int) $item->product_variant_id);
        $this->assertSellablePrice($variant);
        $this->assertAvailableQuantity($variant, max(1, $quantity));

        $item->update(['quantity' => max(1, $quantity)]);

        return $item->refresh();
    }

    public function removeItem(Request $request, CartItem $item): Cart
    {
        $cart = $this->current($request);
        $this->ensureItemBelongsToCart($item, $cart);

        $item->delete();

        return $cart;
    }

    public function clear(Request $request): Cart
    {
        $cart = $this->current($request);
        $cart->items()->delete();
        $cart->forceFill([
            'promo_code_id' => null,
            'promo_code_applied_at' => null,
        ])->save();

        return $cart;
    }

    /** @return array<string, mixed> */
    public function applyPromoCode(Request $request, mixed $rawCode): array
    {
        $code = PromoCode::normalizeCode($rawCode);

        if (! preg_match('/\A[A-Z0-9_-]{3,64}\z/', $code)) {
            throw ValidationException::withMessages([
                'promo_code' => 'Введите код из 3–64 символов: латинские буквы, цифры, дефис или подчёркивание.',
            ]);
        }

        $cart = $this->current($request);

        return DB::transaction(function () use ($cart, $code): array {
            $lockedCart = Cart::query()->whereKey($cart->getKey())->lockForUpdate()->firstOrFail();
            $promo = PromoCode::query()
                ->whereRaw('UPPER(code) = ?', [$code])
                ->lockForUpdate()
                ->first();

            if (! $promo instanceof PromoCode) {
                throw ValidationException::withMessages([
                    'promo_code' => 'Промокод не найден.',
                ]);
            }

            $items = $lockedCart->items()->orderBy('id')->get();
            $activePromoUsageCount = $promo->usage_limit !== null
                ? $promo->activeRedemptions()->count()
                : null;
            $result = $this->promoPricing->calculate(
                $promo,
                $items,
                $activePromoUsageCount,
            );

            if (! $result->valid) {
                throw ValidationException::withMessages([
                    'promo_code' => $result->message ?? 'Промокод не действует.',
                ]);
            }

            $lockedCart->forceFill([
                'promo_code_id' => $promo->getKey(),
                'promo_code_applied_at' => now(),
            ])->save();

            return $this->totals($lockedCart->refresh(), 'Промокод применён.');
        });
    }

    /** @return array<string, mixed> */
    public function removePromoCode(Request $request): array
    {
        $cart = $this->current($request);
        $cart->forceFill([
            'promo_code_id' => null,
            'promo_code_applied_at' => null,
        ])->save();

        return $this->totals($cart->refresh(), 'Промокод удалён.');
    }

    /**
     * @return array<string, mixed>
     */
    public function totals(Cart $cart, ?string $promoMessage = null): array
    {
        if ($cart->promo_code_id === null) {
            $items = $cart->items()->get(['quantity', 'price_snapshot']);
            $subtotal = round((float) $items->sum(
                fn (CartItem $item): float => $item->lineTotal()
            ), 2);

            return $this->totalsPayload(
                (int) $items->sum('quantity'),
                $subtotal,
                0,
                null,
                $promoMessage,
            );
        }

        $items = $cart->items()->orderBy('id')->get();
        $subtotal = round((float) $items->sum(
            fn (CartItem $item): float => $item->lineTotal()
        ), 2);
        $promo = PromoCode::withTrashed()->find($cart->promo_code_id);

        if (! $promo instanceof PromoCode) {
            $this->detachPromo($cart);

            return $this->totalsPayload(
                (int) $items->sum('quantity'),
                $subtotal,
                0,
                null,
                'Промокод больше не действует и был удалён.',
            );
        }

        $result = $this->promoPricing->calculate($promo, $items);

        if (! $result->valid) {
            $this->detachPromo($cart);

            return $this->totalsPayload(
                (int) $items->sum('quantity'),
                $subtotal,
                0,
                null,
                $result->message.' Промокод удалён из корзины.',
            );
        }

        return $this->totalsPayload(
            (int) $items->sum('quantity'),
            $subtotal,
            $result->discountAmount(),
            $promo,
            $promoMessage,
        );
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

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return $this->totalsPayload(0, 0, 0);
    }

    /** @return array<string, mixed> */
    private function totalsPayload(
        int $itemsCount,
        float $subtotal,
        float $discount,
        ?PromoCode $promo = null,
        ?string $promoMessage = null,
    ): array {
        $discount = round(min($subtotal, max(0, $discount)), 2);

        return [
            'items_count' => $itemsCount,
            'subtotal' => round($subtotal, 2),
            'discount_total' => $discount,
            'total' => round(max(0, $subtotal - $discount), 2),
            'promo_applied' => $promo instanceof PromoCode,
            'promo_code' => $promo?->code,
            'promo_name' => $promo?->name,
            'promo_message' => $promoMessage,
        ];
    }

    private function detachPromo(Cart $cart): void
    {
        $cart->forceFill([
            'promo_code_id' => null,
            'promo_code_applied_at' => null,
        ])->save();
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
            ->tap(fn ($query) => $this->availability->variants($query))
            ->first();

        if (! $variant) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Товар недоступен для добавления в корзину.',
            ]);
        }

        return $variant;
    }

    private function ensureItemBelongsToCart(CartItem $item, Cart $cart): void
    {
        abort_unless((int) $item->cart_id === (int) $cart->getKey(), 404);
    }

    private function assertAvailableQuantity(ProductVariant $variant, int $quantity): void
    {
        if ($variant->stock_status === StockStatus::OutOfStock
            || ($variant->stock_status === StockStatus::InStock
                && $variant->stock_quantity !== null
                && $quantity > $variant->stock_quantity)) {
            throw ValidationException::withMessages([
                'quantity' => 'Запрошенное количество товара сейчас недоступно.',
            ]);
        }
    }

    private function assertSellablePrice(ProductVariant $variant): void
    {
        if (! $this->availability->hasSellablePrice($variant)) {
            throw ValidationException::withMessages([
                'product_variant_id' => self::PRICE_UNAVAILABLE_MESSAGE,
            ]);
        }
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
