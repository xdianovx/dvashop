@props(['item'])

@php
    $productUrl = $item->variant?->product ? route('products.show', $item->variant->product->slug) : null;
@endphp

<article class="cart-item" data-cart-item data-cart-item-id="{{ $item->getKey() }}">
    <div class="cart-item__image"><img src="{{ $item->image_snapshot }}" alt="{{ $item->title_snapshot }}" loading="lazy"></div>
    <div class="cart-item__info">
        @if ($productUrl)<a href="{{ $productUrl }}" class="cart-item__name">{{ $item->title_snapshot }}</a>@else<span class="cart-item__name">{{ $item->title_snapshot }}</span>@endif
        @if ($item->optionSummary() !== '')<p class="cart-item__opts">{{ $item->optionSummary() }}</p>@endif
        <form action="{{ route('cart.items.destroy', $item) }}" method="post" data-cart-remove>@csrf @method('DELETE')<button type="submit" class="cart-item__remove">Удалить</button></form>
    </div>
    <div class="cart-item__qty-col">
        <form class="cart-item__qty" action="{{ route('cart.items.update', $item) }}" method="post" data-cart-update>
            @csrf @method('PATCH')
            <button type="submit" name="quantity" value="{{ max(1, $item->quantity - 1) }}" class="cart-item__qty-btn" aria-label="Убавить" @disabled($item->quantity <= 1)>−</button>
            <span class="cart-item__qty-value" aria-live="polite" data-cart-item-quantity>{{ $item->quantity }}</span>
            <button type="submit" name="quantity" value="{{ $item->quantity + 1 }}" class="cart-item__qty-btn" aria-label="Добавить">+</button>
        </form>
        @if ((float) $item->price_snapshot > 0)
            <span class="cart-item__unit">{{ number_format((float) $item->price_snapshot, 0, ',', ' ') }} ₽ за шт.</span>
        @endif
    </div>
    <div class="cart-item__price">
        @if ((float) $item->price_snapshot > 0)
            <span class="cart-item__sum" data-cart-item-line-total>{{ number_format($item->lineTotal(), 0, ',', ' ') }} ₽</span>
        @else
            <span class="cart-item__sum">Цена по запросу</span>
        @endif
    </div>
</article>
