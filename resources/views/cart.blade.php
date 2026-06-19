@extends('layouts.app')

@section('title', 'Моя корзина — 2POROGA')

@php
    $items = [
        ['name' => 'Кузовной порог для Alfa Romeo 33 (1990–1994)', 'options' => 'Оцинкованная • 1 мм • левый', 'qty' => 2, 'price' => '3 500 руб.', 'unit' => '1 750 руб. за шт.'],
        ['name' => 'Арка для Alfa Romeo 33 (1990–1994)', 'options' => 'Оцинкованная • 1 мм • левый', 'qty' => 1, 'price' => '1 750 руб.', 'unit' => '1 750 руб. за шт.'],
    ];
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Моя корзина']]" />

        <h1 class="cart-title">Моя корзина</h1>

        <div class="cart-layout">
            <div class="cart-list">
                @foreach ($items as $item)
                    <x-cart-item :name="$item['name']" :options="$item['options']" :qty="$item['qty']"
                        :price="$item['price']" :unit="$item['unit']" />
                @endforeach
            </div>

            <x-cart-summary count="3 товара" subtotal="5 250 руб." total="5 250 руб." />
        </div>
    </div>
@endsection
