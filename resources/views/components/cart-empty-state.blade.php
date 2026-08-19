<div class="cart-empty" data-cart-empty-state>
    <span class="cart-empty__icon" aria-hidden="true">
        <img src="/img/icons/header-cart.svg" alt="" width="47" height="39">
    </span>
    <h2 class="cart-empty__title">В корзине пока пусто</h2>
    <p class="cart-empty__text">Подберите пороги, арки или усилители по марке автомобиля — добавленные товары появятся здесь.</p>
    <div class="cart-empty__actions">
        <a href="{{ route('catalog.index') }}" class="btn btn--primary cart-empty__action">Перейти в каталог</a>
        <a href="{{ route('favorites.show') }}" class="btn btn--outline cart-empty__action">Избранное</a>
    </div>
</div>
