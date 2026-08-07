<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\ViewData\Storefront\PartnersPageViewData;
use Illuminate\View\View;

final class PartnersController extends Controller
{
    public function __invoke(PartnersPageViewData $pageData): View
    {
        return view('partners', compact('pageData'));
    }
}
