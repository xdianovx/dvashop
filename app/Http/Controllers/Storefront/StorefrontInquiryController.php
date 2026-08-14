<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreStorefrontInquiryRequest;
use App\Services\Storefront\StorefrontInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class StorefrontInquiryController extends Controller
{
    public function __invoke(
        StoreStorefrontInquiryRequest $request,
        StorefrontInquiryService $service,
    ): JsonResponse|RedirectResponse {
        $inquiry = $service->create($request->validated());
        $message = 'Спасибо! Заявка принята. Мы свяжемся с вами.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'inquiry_id' => $inquiry->getKey(),
            ], 201);
        }

        return back()->with('inquiry_success', $message);
    }
}
