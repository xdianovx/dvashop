@extends('layouts.app')

@section('title', 'Кузовные элементы <br> для Volkswagen Golf 5 Plus — 2POROGA')

@php
    $categories = [
        ['label' => 'Кузовные пороги', 'active' => true],
        ['label' => 'Внутренние пороги', 'active' => false],
        ['label' => 'Усилители/соединители порогов', 'active' => false],
        ['label' => 'Поддомкратники', 'active' => false],
        ['label' => 'Торцевые заглушки', 'active' => false],
        ['label' => 'Ремкомплекты пола', 'active' => false],
        ['label' => 'Сегменты ремкомплекта пола', 'active' => false],
        ['label' => 'Лонжероны пола', 'active' => false],
        ['label' => 'Ленты бензобака', 'active' => false],
    ];

    $products = [
        ['name' => 'Порог', 'price' => '1 790', 'old' => null],
        ['name' => 'Порог', 'price' => '1 790', 'old' => null],
        ['name' => 'Порог', 'price' => '1 790', 'old' => '1 950'],
        ['name' => 'Порог', 'price' => '1 790', 'old' => null],
        ['name' => 'Порог', 'price' => '1 790', 'old' => null],
        ['name' => 'Порог', 'price' => '1 790', 'old' => '1 950'],
        ['name' => 'Порог', 'price' => '1 790', 'old' => null],
        ['name' => 'Порог', 'price' => '1 790', 'old' => null],
        ['name' => 'Порог', 'price' => '1 790', 'old' => '1 950'],
    ];
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[
            ['label' => 'Главная', 'url' => '/'],
            ['label' => 'Каталог', 'url' => '/catalog'],
            ['label' => 'Volkswagen', 'url' => '#'],
            ['label' => 'Golf 5 Plus', 'url' => '#'],
            ['label' => 'Volkswagen Golf 5 Plus 5дв. 1 поколение'],
        ]" />

        <div class="product-head">
            <span class="product-head__thumb">
                <img src="/img/cars/golf-5-plus.png" alt="Volkswagen Golf 5 Plus" loading="lazy">
            </span>
            <div class="product-head__info">
                <h1 class="product-head__title">Кузовные элементы <br> для Volkswagen Golf 5 Plus</h1>
                <p class="product-head__meta">Хэтчбек • 5 дверей • 1 поколение • 2025 год</p>
            </div>
        </div>

        <form class="car-search" action="#" method="get">
            <input type="text" class="car-search__input" placeholder="Поиск: порог, усилитель, заглушка…">
            <button type="submit" class="btn btn--primary car-search__submit">Показать</button>
        </form>

        <div class="product-layout">
            <button type="button" class="catalog-trigger" data-catalog-open aria-haspopup="dialog">
                <span>Категории</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </button>

            <aside class="catalog-nav" data-catalog-nav>
                <div class="catalog-nav__panel">
                    <div class="catalog-nav__bar">
                        <span class="catalog-nav__title">Категории</span>
                        <button type="button" class="catalog-nav__toggle" data-catalog-toggle aria-expanded="true">
                            Свернуть
                        </button>
                    </div>
                    <ul class="catalog-nav__list">
                        <li>
                            <a href="#" class="catalog-nav__link catalog-nav__link--all catalog-nav__link--active">
                                Все элементы
                            </a>
                        </li>
                        @foreach ($categories as $category)
                            <li>
                                <a href="#" class="catalog-nav__link">{{ $category['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </aside>

            <ul class="products">
                @foreach ($products as $product)
                    <li class="products__item">
                        <x-product-card :name="$product['name']" :price="$product['price']" :old="$product['old']" />
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection
