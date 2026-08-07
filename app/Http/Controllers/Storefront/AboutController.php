<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\ViewData\Storefront\AboutPageViewData;
use Illuminate\View\View;

final class AboutController extends Controller
{
    public function __invoke(AboutPageViewData $pageData): View
    {
        return view('about', compact('pageData'));
    }
}
