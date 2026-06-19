@extends('layouts.app')

@section('title', 'Кузовной порог для Alfa Romeo 33 (1990–1994) — 2POROGA')

@php
    $gallery = ['/img/products/threshold.png', '/img/products/threshold.png', '/img/products/threshold.png', '/img/products/threshold.png'];

    $profiles = ['Полный', 'Нижняя часть'];

    $radioGroups = [
        ['name' => 'position', 'label' => 'Положение:', 'items' => ['Левый', 'Правый', 'Левый + Правый']],
        ['name' => 'material', 'label' => 'Материал:', 'items' => ['Оцинковка', 'Х/С сталь']],
        ['name' => 'thickness', 'label' => 'Толщина металла', 'items' => ['1 мм', '1,5 мм']],
    ];

    $delivery = [
        ['icon' => 'cost', 'text' => 'Стоимость доставки: от 490 руб.'],
        ['icon' => 'deliver', 'text' => 'Расчётное время доставки: 1–3 дня'],
        ['icon' => 'vozvrat', 'text' => 'Возврат товара: в течение 2 недель'],
    ];

    $related = [
        ['name' => 'Внутренний порог', 'price' => '1 790', 'old' => '1 950'],
        ['name' => 'Внутренний порог', 'price' => '1 790', 'old' => '1 950'],
        ['name' => 'Внутренний порог', 'price' => '1 790', 'old' => '1 950'],
        ['name' => 'Внутренний порог', 'price' => '1 790', 'old' => '1 950'],
    ];
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[
            ['label' => 'Главная', 'url' => '/'],
            ['label' => 'Каталог', 'url' => '/catalog'],
            ['label' => 'Alfa Romeo', 'url' => '#'],
            ['label' => '33', 'url' => '#'],
            ['label' => 'Кузовные пороги', 'url' => '#'],
            ['label' => 'Кузовной порог для Alfa Romeo 33 (1990–1994)'],
        ]" />

        <div class="part-top">
            <div class="part-gallery">
                <div class="part-gallery__main-wrap">
                    <div class="swiper part-gallery__main" data-gallery-main>
                        <div class="swiper-wrapper">
                            @foreach ($gallery as $img)
                                <div class="swiper-slide part-gallery__slide">
                                    <img src="{{ $img }}" alt="Кузовной порог" loading="lazy">
                                </div>
                            @endforeach
                        </div>
                        <div class="part-gallery__pagination"></div>
                    </div>
                    <button type="button" class="part-gallery__fav" aria-label="В избранное">
                        <img src="/img/product/heart.svg" alt="" aria-hidden="true">
                    </button>
                </div>

                <div class="swiper part-gallery__thumbs" data-gallery-thumbs>
                    <div class="swiper-wrapper">
                        @foreach ($gallery as $img)
                            <div class="swiper-slide part-gallery__thumb">
                                <img src="{{ $img }}" alt="" aria-hidden="true">
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="part-buy">
                <div class="part-buy__rating">
                    <span class="part-stars" aria-label="Рейтинг 5 из 5">
                        @for ($i = 0; $i < 5; $i++)
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="m10 1 2.6 5.3 5.9.9-4.2 4.1 1 5.8L10 14.8 4.7 17.6l1-5.8L1.5 7.7l5.9-.9z" />
                            </svg>
                        @endfor
                    </span>
                    <a href="#" class="part-buy__reviews-link">5 оценок</a>
                </div>

                <h1 class="part-buy__title">Кузовной порог для Alfa Romeo 33 (1990–1994)</h1>
                <p class="part-buy__article">Артикул: 01.AR0033XXXX.ALL.0.00</p>
                <p class="part-buy__stock">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="10" cy="10" r="8.5" />
                        <path d="m6.5 10 2.5 2.5 4.5-5" />
                    </svg>
                    Готово к отправке
                </p>
                <p class="part-buy__price">1 990 руб.</p>

                <div class="part-option-group">
                    <span class="part-option-group__label">Профиль:</span>
                    <div class="part-tabs">
                        @foreach ($profiles as $i => $profile)
                            <button type="button" class="part-tab @if ($i === 0) part-tab--active @endif">
                                {{ $profile }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @foreach ($radioGroups as $group)
                    <div class="part-option-group">
                        <span class="part-option-group__label">{{ $group['label'] }}</span>
                        <div class="part-radios">
                            @foreach ($group['items'] as $i => $item)
                                <label class="part-radio">
                                    <input type="radio" name="{{ $group['name'] }}" @if ($i === 0) checked @endif>
                                    <span class="part-radio__dot"></span>
                                    <span class="part-radio__label">{{ $item }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <ul class="part-delivery">
                    @foreach ($delivery as $row)
                        <li class="part-delivery__row">
                            <span class="part-delivery__info">
                                <span class="part-delivery__icon" aria-hidden="true">
                                    <img src="/img/part/{{ $row['icon'] }}.svg" alt="">
                                </span>
                                {{ $row['text'] }}
                            </span>
                            <a href="#" class="part-delivery__more">Подробнее ›</a>
                        </li>
                    @endforeach
                </ul>

                <div class="part-buy__actions">
                    <button type="button" class="btn part-buy__cart">Добавить в корзину</button>
                    <button type="button" class="btn btn--outline part-buy__consult">Получить консультацию</button>
                </div>
            </div>
        </div>

        <section class="part-related">
            <h2 class="part-related__title">С этим товаром покупают</h2>
            <ul class="products">
                @foreach ($related as $product)
                    <li class="products__item">
                        <x-product-card :name="$product['name']" :price="$product['price']" :old="$product['old']" />
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <x-faq />
@endsection
