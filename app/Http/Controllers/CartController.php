<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Services\CartManager;
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

        return view('cart', [
            'cart' => $cart,
            'items' => $items,
            'totals' => $cartManager->totals($cart),
        ]);
    }

    public function storeItem(Request $request, CartManager $cartManager): RedirectResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $cartManager->addItem(
            $request,
            (int) $data['product_variant_id'],
            (int) ($data['quantity'] ?? 1)
        );

        return redirect()->route('cart.show');
    }

    public function updateItem(Request $request, CartItem $item, CartManager $cartManager): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $cartManager->updateQuantity($request, $item, (int) $data['quantity']);

        return redirect()->route('cart.show');
    }

    public function destroyItem(Request $request, CartItem $item, CartManager $cartManager): RedirectResponse
    {
        $cartManager->removeItem($request, $item);

        return redirect()->route('cart.show');
    }

    public function clear(Request $request, CartManager $cartManager): RedirectResponse
    {
        $cartManager->clear($request);

        return redirect()->route('cart.show');
    }
}
