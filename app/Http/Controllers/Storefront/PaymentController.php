<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\ViewData\Storefront\PaymentPageViewData;
use Illuminate\View\View;

final class PaymentController extends Controller
{
    public function __invoke(PaymentPageViewData $pageData): View
    {
        return view('payment', compact('pageData'));
    }
}
