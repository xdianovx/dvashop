@props([
    'card' => null,
    'name' => '',
    'price' => '',
    'old' => null,
    'image' => '/img/products/threshold.png',
    'url' => '#',
])

@php
    if ($card) {
        $name = $card->title;
        $price = $card->price;
        $old = $card->oldPrice;
        $image = $card->image;
        $url = $card->url;
    }
@endphp

<article class="product-card">
    <div class="product-card__image">
        <a href="{{ $url }}">
            <img src="{{ $image }}" alt="{{ $name }}" loading="lazy">
        </a>
        <button type="button" class="product-card__fav" aria-label="В избранное">
            <img src="/img/product/heart.svg" alt="" aria-hidden="true">
        </button>
    </div>

    <div class="product-card__row">
        <span class="product-card__name">{{ $name }}</span>
        <span class="product-card__price @if ($old) product-card__price--sale @endif">
            {{ $price }} ₽
            @if ($old)
                <span class="product-card__old">{{ $old }} ₽</span>
            @endif
        </span>
    </div>

    <a href="{{ $url }}" class="btn btn--outline product-card__more">Подробнее</a>
    <button type="button" class="btn product-card__buy">
        <span class="product-card__buy-full">Заказать в 1 клик</span>
        <span class="product-card__buy-short">Заказать</span>
    </button>
</article>
