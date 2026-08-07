<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\ViewData\Storefront\HowPageViewData;
use Illuminate\View\View;

final class HowController extends Controller
{
    public function __invoke(HowPageViewData $pageData): View
    {
        return view('how', compact('pageData'));
    }
}
