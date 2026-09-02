@props(['totals', 'hasUnavailablePrices' => false])

<aside class="cart-summary">
    <h2 class="cart-summary__title">Ваш заказ</h2>
    @if ($hasUnavailablePrices)
        <div class="cart-summary__row cart-summary__row--total"><span>Стоимость</span><span class="cart-summary__value">Требуется уточнение</span></div>
    @else
        <div class="cart-summary__row"><span><span data-cart-items-count>{{ $totals['items_count'] }}</span> товар(ов) на сумму</span><span class="cart-summary__value" data-cart-subtotal>{{ number_format((float) $totals['subtotal'], 0, ',', ' ') }} ₽</span></div>
        <div class="cart-summary__row cart-summary__row--discount" data-cart-discount-row @if ($totals['discount_total'] <= 0) hidden @endif><span>Скидка</span><span class="cart-summary__value" data-cart-discount>−{{ number_format((float) $totals['discount_total'], 0, ',', ' ') }} ₽</span></div>
        <div class="cart-summary__row cart-summary__row--total"><span>Итого</span><span class="cart-summary__value" data-cart-total>{{ number_format((float) $totals['total'], 0, ',', ' ') }} ₽</span></div>
        <x-promo-code-form :totals="$totals" />
    @endif
    <div class="cart-summary__actions">
        @unless ($hasUnavailablePrices)
            <a href="{{ route('checkout.show') }}" class="btn btn--primary cart-summary__checkout">Оформить заказ</a>
        @endunless
    </div>
</aside>
