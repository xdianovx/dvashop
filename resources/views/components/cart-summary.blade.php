@props(['count' => 0, 'subtotal' => 0, 'hasUnavailablePrices' => false])

<aside class="cart-summary">
    <h2 class="cart-summary__title">Ваш заказ</h2>
    @if ($hasUnavailablePrices)
        <div class="cart-summary__row cart-summary__row--total"><span>Стоимость</span><span class="cart-summary__value">Требуется уточнение</span></div>
    @else
        <div class="cart-summary__row"><span><span data-cart-items-count>{{ $count }}</span> товар(ов) на сумму</span><span class="cart-summary__value" data-cart-subtotal>{{ number_format((float) $subtotal, 0, ',', ' ') }} ₽</span></div>
        <div class="cart-summary__row cart-summary__row--total"><span>Итого</span><span class="cart-summary__value" data-cart-total>{{ number_format((float) $subtotal, 0, ',', ' ') }} ₽</span></div>
    @endif
    <div class="cart-summary__actions">
        @unless ($hasUnavailablePrices)
            <a href="{{ route('checkout.show') }}" class="btn btn--primary cart-summary__checkout">Оформить заказ</a>
        @endunless
        <form action="{{ route('cart.clear') }}" method="post" data-cart-clear>@csrf @method('DELETE')<button type="submit" class="btn btn--outline">Очистить корзину</button></form>
    </div>
</aside>
