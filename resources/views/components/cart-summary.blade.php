@props(['count' => 0, 'subtotal' => 0])

<aside class="cart-summary">
    <h2 class="cart-summary__title">Ваш заказ</h2>
    <div class="cart-summary__row"><span>{{ $count }} товар(ов) на сумму</span><span class="cart-summary__value">{{ number_format((float) $subtotal, 0, ',', ' ') }} ₽</span></div>
    <div class="cart-summary__row cart-summary__row--total"><span>Итого</span><span class="cart-summary__value">{{ number_format((float) $subtotal, 0, ',', ' ') }} ₽</span></div>
    <div class="cart-summary__actions">
        <a href="{{ route('checkout.show') }}" class="btn btn--primary cart-summary__checkout">Оформить заказ</a>
        <form action="{{ route('cart.clear') }}" method="post">@csrf @method('DELETE')<button type="submit" class="btn btn--outline">Очистить корзину</button></form>
    </div>
</aside>
