<?php

namespace App\Http\Controllers;

use App\Services\CartManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartPromoCodeController extends Controller
{
    public function store(Request $request, CartManager $cartManager): JsonResponse|RedirectResponse
    {
        $totals = $cartManager->applyPromoCode($request, $request->input('promo_code'));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $totals['promo_message'],
                'cart' => $totals,
            ]);
        }

        return redirect()->back()->with('promo_status', $totals['promo_message']);
    }

    public function destroy(Request $request, CartManager $cartManager): JsonResponse|RedirectResponse
    {
        $totals = $cartManager->removePromoCode($request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $totals['promo_message'],
                'cart' => $totals,
            ]);
        }

        return redirect()->back()->with('promo_status', $totals['promo_message']);
    }
}
