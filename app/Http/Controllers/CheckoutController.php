<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Services\CartManager;
use App\Services\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    private const SUCCESS_SESSION_PREFIX = 'checkout_success.';

    public function show(Request $request, CartManager $cartManager): View
    {
        $cart = $cartManager->current($request);
        $items = $cart->items()->orderBy('id')->get();

        return view('checkout', [
            'cart' => $cart,
            'items' => $items,
            'totals' => $cartManager->totals($cart),
            'deliveryMethods' => DeliveryMethodSetting::query()->active()->ordered()->get(),
            'paymentMethods' => PaymentMethodSetting::query()->active()->ordered()->get(),
            'deliveryPresentation' => $this->deliveryPresentation(),
            'paymentIcons' => $this->paymentIcons(),
        ]);
    }

    public function store(Request $request, CheckoutService $checkoutService): RedirectResponse
    {
        $order = $checkoutService->createOrderFromCart($request, $request->only([
            'customer_name',
            'customer_phone',
            'customer_email',
            'customer_city',
            'customer_address',
            'customer_comment',
            'delivery_method',
            'payment_method',
            'agree_terms',
        ]));
        $token = Str::random(48);
        $request->session()->put(self::SUCCESS_SESSION_PREFIX.$order->getKey(), $token);

        return redirect()->route('checkout.success', ['order' => $order->number, 'token' => $token]);
    }

    public function success(Request $request, Order $order): View
    {
        $expectedToken = $request->session()->get(self::SUCCESS_SESSION_PREFIX.$order->getKey());
        $providedToken = (string) $request->query('token', '');

        abort_unless(is_string($expectedToken) && $providedToken !== '' && hash_equals($expectedToken, $providedToken), 404);

        return view('thanks', [
            'order' => $order->load('items'),
        ]);
    }

    /** @return array<string, array{image: string, width: int, height: int}> */
    private function deliveryPresentation(): array
    {
        return [
            DeliveryMethod::TransportCompany->value => [
                'image' => '/img/checkout/cdek.svg',
                'width' => 75,
                'height' => 21,
            ],
            DeliveryMethod::Pickup->value => [
                'image' => '/img/checkout/pickup.svg',
                'width' => 39,
                'height' => 38,
            ],
        ];
    }

    /** @return array<string, string> */
    private function paymentIcons(): array
    {
        return [
            PaymentMethod::Card->value => '💳',
            PaymentMethod::Sbp->value => '⚡',
            PaymentMethod::Invoice->value => '📄',
            PaymentMethod::CashOnDelivery->value => '🤝',
        ];
    }
}
