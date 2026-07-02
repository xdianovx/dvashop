@extends('layouts.app')

@if (! empty($metaDescription))
    @section('meta_description', $metaDescription)
@endif

@section('title', $pageTitle ?? $product->title.' — 2POROGA')

@php
    $delivery = [
        ['icon' => 'cost', 'text' => 'Стоимость доставки: от 490 руб.'],
        ['icon' => 'deliver', 'text' => 'Расчётное время доставки: 1–3 дня'],
        ['icon' => 'vozvrat', 'text' => 'Возврат товара: в течение 2 недель'],
    ];

    $stockLabel = $variant->stock_status?->label() ?? $product->stock_status?->label() ?? 'В наличии';
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="$breadcrumbs" />

        <div class="part-top">
            <div class="part-gallery">
                <div class="part-gallery__main-wrap">
                    <div class="swiper part-gallery__main" data-gallery-main>
                        <div class="swiper-wrapper">
                            @foreach ($gallery as $img)
                                <div class="swiper-slide part-gallery__slide">
                                    <img src="{{ $img['url'] }}" alt="{{ $img['alt'] }}" loading="lazy">
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
                                <img src="{{ $img['url'] }}" alt="" aria-hidden="true">
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
                    <a href="#" class="part-buy__reviews-link">Нет оценок</a>
                </div>

                <h1 class="part-buy__title">{{ $product->title }}</h1>
                <p class="part-buy__article">Артикул: {{ $variant->sku ?: $product->sku ?: '—' }}</p>
                <p class="part-buy__stock">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="10" cy="10" r="8.5" />
                        <path d="m6.5 10 2.5 2.5 4.5-5" />
                    </svg>
                    {{ $stockLabel }}
                </p>
                <p class="part-buy__price">{{ \App\ViewModels\ProductCardViewModel::formatPrice($variant->price) }} руб.</p>

                @if ($product->variants->count() > 1)
                    <div class="part-option-group">
                        <span class="part-option-group__label">Вариант:</span>
                        <div class="part-tabs">
                            @foreach ($product->variants as $item)
                                <button type="button" class="part-tab @if ($item->is_default) part-tab--active @endif">
                                    {{ $item->title ?: 'Стандарт' }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

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

                <form id="product-add-to-cart-form" action="{{ route('cart.items.store') }}" method="post">
                    @csrf
                    <input type="hidden" name="product_variant_id" value="{{ $variant->getKey() }}">
                    <input type="hidden" name="quantity" value="1">
                </form>

                <div class="part-buy__actions">
                    <button type="submit" form="product-add-to-cart-form" class="btn part-buy__cart">Добавить в корзину</button>
                    <button type="button" class="btn btn--outline part-buy__consult">Получить консультацию</button>
                </div>
            </div>
        </div>

        <section class="part-info">
            <div class="part-info__col">
                <h2 class="part-info__heading">Описание</h2>
                @if ($description)
                    <div class="part-desc">{!! nl2br(e($description)) !!}</div>
                @else
                    <p class="part-desc">Описание товара скоро появится.</p>
                @endif
            </div>

            <div class="part-info__col">
                <h2 class="part-info__heading">Характеристики</h2>
                <dl class="part-specs">
                    <div class="part-specs__row">
                        <dt class="part-specs__key">Артикул</dt>
                        <dd class="part-specs__val">{{ $variant->sku ?: $product->sku ?: '—' }}</dd>
                    </div>
                    <div class="part-specs__row">
                        <dt class="part-specs__key">Категория</dt>
                        <dd class="part-specs__val">{{ $product->category?->title ?: '—' }}</dd>
                    </div>
                    <div class="part-specs__row">
                        <dt class="part-specs__key">Марка</dt>
                        <dd class="part-specs__val">{{ $make?->title ?: '—' }}</dd>
                    </div>
                    <div class="part-specs__row">
                        <dt class="part-specs__key">Модель</dt>
                        <dd class="part-specs__val">{{ $model?->title ?: '—' }}</dd>
                    </div>
                    <div class="part-specs__row">
                        <dt class="part-specs__key">Поколение</dt>
                        <dd class="part-specs__val">{{ $generation?->title ?: '—' }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="part-related">
            <h2 class="part-related__title">С этим товаром покупают</h2>
            <ul class="products">
                @forelse ($related as $product)
                    <li class="products__item">
                        <x-product-card :card="$product" />
                    </li>
                @empty
                    <li class="products__item">Похожие товары пока не найдены</li>
                @endforelse
            </ul>
        </section>
    </div>

    <x-faq />
@endsection
