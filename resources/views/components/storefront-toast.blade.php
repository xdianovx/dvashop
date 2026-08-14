<div
    class="storefront-toast"
    data-storefront-toast
    role="status"
    aria-live="polite"
    aria-atomic="true"
    hidden
>
    <p class="storefront-toast__message" data-storefront-toast-message></p>
    <div class="storefront-toast__actions">
        <a
            href="{{ route('cart.show') }}"
            class="storefront-toast__link"
            data-storefront-toast-link
            data-default-href="{{ route('cart.show') }}"
            data-default-label="Перейти в корзину"
        >Перейти в корзину</a>
        <button type="button" class="storefront-toast__close" data-storefront-toast-close aria-label="Закрыть уведомление">×</button>
    </div>
</div>
