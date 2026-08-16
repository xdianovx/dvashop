@props(['product'])

@php
    $isFavorite = in_array((int) $product->id, $favoriteProductIds ?? [], true);
@endphp

<article class="related-card">
    <div class="related-card__media">
        <a href="{{ $product->url }}" class="related-card__image">
            <img src="{{ $product->image }}" alt="{{ $product->title }}" loading="lazy">
        </a>
        <x-favorite-toggle
            :product-id="$product->id"
            :is-favorite="$isFavorite"
            button-class="related-card__fav"
        />
    </div>
    <p class="related-card__price @if ($product->oldPrice) related-card__price--sale @endif">
        {{ $product->priceLabel }}
        @if ($product->oldPrice)<span class="related-card__old">{{ $product->oldPrice }} ₽</span>@endif
    </p>
    <a href="{{ $product->url }}" class="related-card__title">{{ $product->title }}</a>
    <p class="related-card__stock related-card__stock--{{ $product->inStock ? 'in' : 'out' }}">
        @if ($product->inStock)
            <span class="related-card__stock-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="10" cy="10" r="8.5" />
                    <path d="m6.5 10 2.5 2.5 4.5-5" />
                </svg>
            </span>
            <span>Готово к отправке</span>
        @else
            <span>Нет в наличии</span>
        @endif
    </p>
</article>
