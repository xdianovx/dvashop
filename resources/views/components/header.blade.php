<header class="header">
    <div class="header__top">
        <div class="container header__top-inner">
            <nav class="header__utils" aria-label="Дополнительное меню">
                <a href="#" class="header__util-link">Партнерам</a>
                <a href="#" class="header__util-link">О нас</a>
                <a href="#" class="header__util-link">Как мы работаем</a>
                <a href="#" class="header__util-link">Оплата и доставка</a>
                <a href="#" class="header__util-link">Вопросы и ответы</a>
                <a href="#" class="header__util-link">Возвраты и обмен</a>
            </nav>
        </div>
    </div>

    <div class="header__bar">
        <div class="container header__bar-inner">

            <x-burger />

            <a href="/" class="header__logo" aria-label="2POROGA — на главную">
                <img src="/img/logo.svg" alt="AVTOPOROGI.ru" width="253" height="33">
            </a>

            <nav class="header__nav" aria-label="Основное меню">
                <span class="header__nav-sep" aria-hidden="true"></span>
                <a href="{{ route('catalog.index') }}" class="header__nav-link">Каталог</a>
                <a href="#" class="header__nav-link">Отзывы</a>
                <a href="#" class="header__nav-link">Контакты</a>
                <span class="header__nav-sep" aria-hidden="true"></span>
            </nav>


            <div class="header__left">

                <a href="tel:88001005625" class="header__phone">
                    <img class="header__phone-icon" src="/img/icons/header-call.svg" alt="" aria-hidden="true"
                        width="28" height="27">
                    <span class="header__phone-text">
                        <span class="header__phone-number">8 800 100 56 25</span>
                        <span class="header__phone-caption">Бесплатный звонок</span>
                    </span>
                </a>

                <div class="header__actions">
                    <a href="#" class="header__action" aria-label="Избранное">
                        <img src="/img/icons/header-heart.svg" alt="" aria-hidden="true" width="42"
                            height="36">
                    </a>
                    <a href="{{ route('cart.show') }}" class="header__action" aria-label="Корзина">
                        <img src="/img/icons/header-cart.svg" alt="" aria-hidden="true" width="47"
                            height="39">
                    </a>
                </div>
            </div>

        </div>
    </div>
</header>
