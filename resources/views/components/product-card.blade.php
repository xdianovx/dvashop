@props(['product'])

@php
    $isFavorite = in_array((int) $product->id, $favoriteProductIds ?? [], true);
@endphp

<article class="product-card">
    <x-favorite-toggle :product-id="$product->id" :is-favorite="$isFavorite" button-class="product-card__fav" />
    <a href="{{ $product->url }}" class="product-card__image">
        <img src="{{ $product->image }}" alt="{{ $product->title }}" loading="lazy">
    </a>
    <div class="product-card__row">
        <a href="{{ $product->url }}" class="product-card__name">{{ $product->title }}</a>
        <span class="product-card__price @if ($product->oldPrice) product-card__price--sale @endif">
            {{ $product->priceLabel }}
            @if ($product->oldPrice)
                <span class="product-card__old">{{ $product->oldPrice }} ₽</span>
            @endif
        </span>
    </div>
    <a href="{{ $product->url }}" class="btn btn--outline product-card__more">Подробнее</a>
</article>
