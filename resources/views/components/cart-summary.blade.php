@props([
    'count' => '',
    'subtotal' => '',
    'total' => '',
])

<aside class="cart-summary">
    <h2 class="cart-summary__title">Ваш заказ</h2>

    <div class="cart-summary__row">
        <span>{{ $count }} на сумму</span>
        <span class="cart-summary__value">{{ $subtotal }}</span>
    </div>

    <div class="cart-summary__row cart-summary__row--total">
        <span>Итого</span>
        <span class="cart-summary__value">{{ $total }}</span>
    </div>

    <button type="button" class="cart-summary__promo" data-promo-toggle>У меня есть промокод</button>

    <div class="cart-summary__actions">
        <button type="button" class="btn btn--primary cart-summary__checkout">Оформить заказ</button>
        <button type="button" class="btn btn--outline cart-summary__oneclick">Заказать в 1 клик</button>
    </div>
</aside>
