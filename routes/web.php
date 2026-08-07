<?php

use App\Http\Controllers\Storefront\AboutController;
use App\Http\Controllers\Storefront\FaqController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\HowController;
use App\Http\Controllers\Storefront\LegalDocumentController;
use App\Http\Controllers\Storefront\PartnersController;
use App\Http\Controllers\Storefront\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::view('/catalog', 'catalog')->name('catalog.index');
Route::view('/brand', 'brand')->name('catalog.make');
Route::view('/model', 'model')->name('catalog.model');
Route::view('/car', 'car')->name('catalog.generation');
Route::view('/part', 'part')->name('products.show');
Route::get('/faq', FaqController::class)->name('faq');
Route::get('/payment', PaymentController::class)->name('payment');
Route::get('/how', HowController::class)->name('how');
Route::get('/about', AboutController::class)->name('about');
Route::get('/partners', PartnersController::class)->name('partners');
Route::view('/cart', 'cart')->name('cart.show');
Route::view('/checkout', 'checkout')->name('checkout.show');
Route::view('/thanks', 'thanks')->name('checkout.success');

Route::get('/documents/privacy-policy', [LegalDocumentController::class, 'privacyPolicy'])
    ->name('legal.privacy-policy');
Route::get('/documents/sale-rules', [LegalDocumentController::class, 'saleRules'])
    ->name('legal.sale-rules');
Route::get('/documents/returns-exchange', [LegalDocumentController::class, 'returnsExchange'])
    ->name('legal.returns-exchange');
Route::get('/documents/information-usage-rules', [LegalDocumentController::class, 'informationUsageRules'])
    ->name('legal.information-usage-rules');
