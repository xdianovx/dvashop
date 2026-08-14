<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\Storefront\AboutController;
use App\Http\Controllers\Storefront\FaqController;
use App\Http\Controllers\Storefront\FavoritesController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\HowController;
use App\Http\Controllers\Storefront\LegalDocumentController;
use App\Http\Controllers\Storefront\PartnersController;
use App\Http\Controllers\Storefront\PaymentController;
use App\Http\Controllers\Storefront\StorefrontInquiryController;
use App\Http\Controllers\Storefront\VehicleMakeModelsController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/storefront/vehicle-makes/{makeSlug}/models', VehicleMakeModelsController::class)
    ->name('storefront.vehicle-makes.models');
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{makeSlug}', [CatalogController::class, 'make'])->name('catalog.make');
Route::get('/catalog/{makeSlug}/{modelSlug}', [CatalogController::class, 'model'])->name('catalog.model');
Route::get('/catalog/{makeSlug}/{modelSlug}/{generationSlug}', [CatalogController::class, 'generation'])->name('catalog.generation');
Route::get('/products/{productSlug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/faq', FaqController::class)->name('faq');
Route::get('/payment', PaymentController::class)->name('payment');
Route::get('/how', HowController::class)->name('how');
Route::get('/about', AboutController::class)->name('about');
Route::get('/partners', PartnersController::class)->name('partners');
Route::get('/favorites', [FavoritesController::class, 'index'])->name('favorites.show');
Route::post('/favorites/items', [FavoritesController::class, 'store'])->name('favorites.items.store');
Route::delete('/favorites/items/{product}', [FavoritesController::class, 'destroy'])
    ->whereNumber('product')
    ->name('favorites.items.destroy');
Route::post('/inquiries', StorefrontInquiryController::class)
    ->middleware('throttle:5,1')
    ->name('storefront.inquiries.store');
Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
Route::post('/cart/items', [CartController::class, 'storeItem'])->name('cart.items.store');
Route::patch('/cart/items/{item}', [CartController::class, 'updateItem'])->name('cart.items.update');
Route::delete('/cart/items/{item}', [CartController::class, 'destroyItem'])->name('cart.items.destroy');
Route::delete('/cart', [CartController::class, 'clear'])->name('cart.clear');
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/thanks/{order:number}', [CheckoutController::class, 'success'])->name('checkout.success');

Route::get('/documents/privacy-policy', [LegalDocumentController::class, 'privacyPolicy'])
    ->name('legal.privacy-policy');
Route::get('/documents/sale-rules', [LegalDocumentController::class, 'saleRules'])
    ->name('legal.sale-rules');
Route::get('/documents/returns-exchange', [LegalDocumentController::class, 'returnsExchange'])
    ->name('legal.returns-exchange');
Route::get('/documents/information-usage-rules', [LegalDocumentController::class, 'informationUsageRules'])
    ->name('legal.information-usage-rules');
