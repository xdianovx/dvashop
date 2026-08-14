<?php

namespace App\Http\Middleware;

use App\Services\CartManager;
use App\Services\FavoritesManager;
use App\ViewData\Storefront\GlobalStorefrontData;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final class ShareGlobalStorefrontData
{
    public function __construct(
        private readonly CartManager $cartManager,
        private readonly FavoritesManager $favoritesManager,
    ) {}

    /** @var list<string> */
    private const EXACT_ROUTE_NAMES = [
        'home',
        'about',
        'how',
        'payment',
        'faq',
        'partners',
        'favorites.show',
        'cart.show',
        'checkout.show',
        'checkout.success',
        'products.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($request->isMethodSafe()
            && is_string($routeName)
            && $this->usesStorefrontLayout($routeName)) {
            $favorites = $this->favoritesManager->summaryForRequest($request);

            View::share('storefront', app(GlobalStorefrontData::class));
            View::share('cartCount', $this->cartManager->summaryForRequest($request)['items_count']);
            View::share('favoritesCount', $favorites['count']);
            View::share('favoriteProductIds', $favorites['product_ids']);
        }

        return $next($request);
    }

    private function usesStorefrontLayout(string $routeName): bool
    {
        return in_array($routeName, self::EXACT_ROUTE_NAMES, true)
            || str_starts_with($routeName, 'catalog.')
            || str_starts_with($routeName, 'legal.');
    }
}
