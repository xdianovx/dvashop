<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\ViewData\Storefront\FaqPageViewData;
use Illuminate\View\View;

final class FaqController extends Controller
{
    public function __invoke(FaqPageViewData $pageData): View
    {
        return view('faq', compact('pageData'));
    }
}
