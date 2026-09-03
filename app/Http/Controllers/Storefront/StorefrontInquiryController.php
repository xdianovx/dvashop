<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreStorefrontInquiryRequest;
use App\Services\Integrations\UisPayloadBuilder;
use App\Services\Storefront\StorefrontInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class StorefrontInquiryController extends Controller
{
    public function __invoke(
        StoreStorefrontInquiryRequest $request,
        StorefrontInquiryService $service,
        UisPayloadBuilder $uisPayloadBuilder,
    ): JsonResponse|RedirectResponse {
        $inquiry = $service->create($request->validated());
        $message = 'Спасибо! Заявка принята. Мы свяжемся с вами.';
        $uisPayload = $uisPayloadBuilder->forInquiry($inquiry);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'inquiry_id' => $inquiry->getKey(),
                'uis' => $uisPayload,
            ], 201);
        }

        return back()->with([
            'inquiry_success' => $message,
            'uis_success_payload' => $uisPayload,
        ]);
    }
}
