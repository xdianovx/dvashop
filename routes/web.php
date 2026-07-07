<?php

use Illuminate\Support\Facades\Route;


Route::view('/', 'home')->name('home');
Route::view('/catalog', 'catalog')->name('catalog.index');
Route::view('/car', 'car')->name('catalog.model');
Route::view('/part', 'part')->name('products.show');
Route::view('/faq', 'faq')->name('faq');
Route::view('/payment', 'payment')->name('payment');
Route::view('/cart', 'cart')->name('cart.show');
Route::view('/checkout', 'checkout')->name('checkout.show');
