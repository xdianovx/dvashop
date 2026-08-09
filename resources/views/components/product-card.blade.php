@props(['product'])

<article class="product-card">
    <a href="{{ $product->url }}" class="product-card__image">
        <img src="{{ $product->image }}" alt="{{ $product->title }}" loading="lazy">
    </a>
    <div class="product-card__row">
        <a href="{{ $product->url }}" class="product-card__name">{{ $product->title }}</a>
        <span class="product-card__price @if ($product->oldPrice) product-card__price--sale @endif">
            {{ $product->price }} ₽
            @if ($product->oldPrice)<span class="product-card__old">{{ $product->oldPrice }} ₽</span>@endif
        </span>
    </div>
    <a href="{{ $product->url }}" class="btn btn--outline product-card__more">Подробнее</a>
    @if ($product->variantId)
        <form action="{{ route('cart.items.store') }}" method="post">
            @csrf
            <input type="hidden" name="product_variant_id" value="{{ $product->variantId }}">
            <input type="hidden" name="quantity" value="1">
            <button type="submit" class="btn product-card__buy"><span class="product-card__buy-full">Добавить в корзину</span><span class="product-card__buy-short">В корзину</span></button>
        </form>
    @endif
</article>
