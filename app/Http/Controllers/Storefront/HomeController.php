<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\ViewData\Storefront\HomepageViewData;
use Illuminate\View\View;

final class HomeController extends Controller
{
    public function __invoke(HomepageViewData $pageData): View
    {
        return view('home', compact('pageData'));
    }
}
