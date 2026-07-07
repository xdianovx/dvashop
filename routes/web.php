<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SeoController;
use App\Services\Seo\SeoMetaService;
use Illuminate\Support\Facades\Route;

Route::get('/', function (SeoMetaService $seo) {
    return view('home', $seo->home()->toViewData());
})->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/categories/{categoryFullSlug}', [CatalogController::class, 'category'])
    ->where('categoryFullSlug', '.*')
    ->name('catalog.category');
Route::get('/catalog/{makeSlug}', [CatalogController::class, 'make'])->name('catalog.make');
Route::get('/catalog/{makeSlug}/{modelSlug}', [CatalogController::class, 'model'])->name('catalog.model');
Route::get('/catalog/{makeSlug}/{modelSlug}/{generationSlug}', [CatalogController::class, 'generation'])->name('catalog.generation');

// Public product URLs use /products/{slug}: it maps to the Product entity directly and avoids the old generic /part route.
Route::get('/products/{productSlug}', [ProductController::class, 'show'])->name('products.show');


Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
Route::post('/cart/items', [CartController::class, 'storeItem'])->name('cart.items.store');
Route::patch('/cart/items/{item}', [CartController::class, 'updateItem'])->name('cart.items.update');
Route::delete('/cart/items/{item}', [CartController::class, 'destroyItem'])->name('cart.items.destroy');
Route::delete('/cart', [CartController::class, 'clear'])->name('cart.clear');

Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');

Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
