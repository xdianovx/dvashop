<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\CartManager;
use App\Services\Seo\SeoData;
use App\ViewData\Storefront\GlobalStorefrontData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function show(Request $request, CartManager $cartManager): View
    {
        $cart = $cartManager->current($request);
        $items = $cart->items()
            ->with(['variant.product'])
            ->orderBy('id')
            ->get();
        $hasUnavailablePrices = $items->contains(
            fn (CartItem $item): bool => (float) $item->price_snapshot <= 0
        );

        return view('cart', [
            'cart' => $cart,
            'items' => $items,
            'totals' => $cartManager->totals($cart),
            'hasUnavailablePrices' => $hasUnavailablePrices,
            'seo' => SeoData::technicalPage(
                'Моя корзина — '.app(GlobalStorefrontData::class)->storeName,
                route('cart.show'),
            ),
        ]);
    }

    public function storeItem(Request $request, CartManager $cartManager): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $item = $cartManager->addItem(
            $request,
            (int) $data['product_variant_id'],
            (int) ($data['quantity'] ?? 1)
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Товар добавлен в корзину.',
                'cart' => $this->cartPayload($cartManager, $item->cart()->firstOrFail()),
                'item' => $this->itemPayload($item),
            ], 201);
        }

        return redirect()->route('cart.show');
    }

    public function updateItem(Request $request, CartItem $item, CartManager $cartManager): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $item = $cartManager->updateQuantity($request, $item, (int) $data['quantity']);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Количество товара обновлено.',
                'cart' => $this->cartPayload($cartManager, $item->cart()->firstOrFail()),
                'item' => $this->itemPayload($item),
            ]);
        }

        return redirect()->route('cart.show');
    }

    public function destroyItem(Request $request, CartItem $item, CartManager $cartManager): JsonResponse|RedirectResponse
    {
        $cart = $cartManager->removeItem($request, $item);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Товар удалён из корзины.',
                'cart' => $this->cartPayload($cartManager, $cart),
                'removed_id' => $item->getKey(),
            ]);
        }

        return redirect()->route('cart.show');
    }

    public function clear(Request $request, CartManager $cartManager): JsonResponse|RedirectResponse
    {
        $cart = $cartManager->clear($request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Корзина очищена.',
                'cart' => $this->cartPayload($cartManager, $cart),
            ]);
        }

        return redirect()->route('cart.show');
    }

    /** @return array<string, mixed> */
    private function cartPayload(CartManager $cartManager, Cart $cart): array
    {
        return $cartManager->totals($cart);
    }

    /** @return array{id: int, quantity: int, line_total: float} */
    private function itemPayload(CartItem $item): array
    {
        return [
            'id' => (int) $item->getKey(),
            'quantity' => (int) $item->quantity,
            'line_total' => $item->lineTotal(),
        ];
    }
}
