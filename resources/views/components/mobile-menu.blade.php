<div class="mobile-menu" data-mobile-menu>
    <div class="mobile-menu__head">
        <span class="mobile-menu__title">Меню</span>
        <button type="button" class="mobile-menu__close" data-mobile-menu-close>Свернуть</button>
    </div>
    <nav class="mobile-menu__nav" aria-label="Мобильное меню">
        <a href="{{ route('catalog.index') }}" class="mobile-menu__link mobile-menu__link--catalog">Каталог</a>
        <a href="#" class="mobile-menu__link">О нас</a>
        <a href="{{ route('how') }}" class="mobile-menu__link">Как мы работаем</a>
        <a href="{{ route('payment') }}" class="mobile-menu__link">Оплата и доставка</a>
        <a href="{{ route('faq') }}" class="mobile-menu__link">Вопросы и ответы</a>
        <a href="#" class="mobile-menu__link">Возврат и обмен</a>
    </nav>
</div>
