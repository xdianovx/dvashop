<?php

use Illuminate\Support\Facades\Route;


Route::view('/', 'home')->name('home');
Route::view('/catalog', 'catalog')->name('catalog.index');
Route::view('/brand', 'brand')->name('catalog.make');
Route::view('/model', 'model')->name('catalog.model');
Route::view('/car', 'car')->name('catalog.generation');
Route::view('/part', 'part')->name('products.show');
Route::view('/faq', 'faq')->name('faq');
Route::view('/payment', 'payment')->name('payment');
Route::view('/how', 'how')->name('how');
Route::view('/about', 'about')->name('about');
Route::view('/partners', 'partners')->name('partners');
Route::view('/cart', 'cart')->name('cart.show');
Route::view('/checkout', 'checkout')->name('checkout.show');
