<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\FavoritesManager;
use App\Services\Seo\SeoData;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class FavoritesController extends Controller
{
    public function index(Request $request, FavoritesManager $favorites): View
    {
        return view('favorites', [
            'products' => $favorites->products($request)
                ->map(fn (Product $product): ProductCardViewModel => ProductCardViewModel::fromProduct($product)),
            'breadcrumbs' => [
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'Избранное'],
            ],
            'seo' => SeoData::technicalPage(
                'Избранное — '.app(GlobalStorefrontData::class)->storeName,
                route('favorites.show'),
            ),
        ]);
    }

    public function store(Request $request, FavoritesManager $favorites): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
        ]);
        $productId = (int) $data['product_id'];
        $summary = $favorites->add($request, $productId);
        $message = 'Товар добавлен в избранное.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'count' => $summary['count'],
                'product_id' => $productId,
                'is_favorite' => true,
            ]);
        }

        return redirect()->back()->with('favorites_success', $message);
    }

    public function destroy(Request $request, int $product, FavoritesManager $favorites): JsonResponse|RedirectResponse
    {
        $summary = $favorites->remove($request, $product);
        $message = 'Товар удалён из избранного.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'count' => $summary['count'],
                'product_id' => $product,
                'is_favorite' => false,
            ]);
        }

        return redirect()->back()->with('favorites_success', $message);
    }
}
