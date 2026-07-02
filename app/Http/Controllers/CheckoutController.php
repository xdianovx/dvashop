<?php

namespace App\Http\Controllers;

use App\Services\CartManager;
use App\Services\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function show(Request $request, CartManager $cartManager): View
    {
        $cart = $cartManager->current($request);
        $items = $cart->items()
            ->with(['variant.product'])
            ->orderBy('id')
            ->get();

        return view('checkout', [
            'cart' => $cart,
            'items' => $items,
            'totals' => $cartManager->totals($cart),
        ]);
    }

    public function store(Request $request, CheckoutService $checkoutService): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $order = $checkoutService->createOrderFromCart($request, [
            'customer_name' => $data['name'],
            'customer_phone' => $data['phone'],
            'customer_email' => $data['email'] ?? null,
            'delivery_city' => $data['city'] ?? null,
            'delivery_address' => $data['address'] ?? null,
            'comment' => $data['comment'] ?? null,
        ]);

        return redirect()
            ->route('checkout.show')
            ->with('order_created', 'Заказ '.$order->number.' создан. Менеджер свяжется с вами для подтверждения.');
    }
}
