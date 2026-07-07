<footer class="footer">
    <div class="footer__desktop">
        <div class="container">
            <div class="footer__inner">
                <div class="footer__col footer__col--brand">
                    <a href="/" class="footer__logo" aria-label="2POROGA — на главную">
                        <img src="/img/logo-white.svg" alt="AVTOPOROGI.ru" width="258" height="39">
                    </a>

                    <ul class="footer__links">
                        <li><a href="#" class="footer__link footer__link--arrow">Реквизиты</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Политика конфиденциальности</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Правила использования информации</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Правила продажи</a></li>
                    </ul>
                </div>

                <nav class="footer__col" aria-label="О нас">
                    <h3 class="footer__heading">О нас</h3>
                    <ul class="footer__links">
                        <li><a href="{{ route('how') }}" class="footer__link footer__link--arrow">Как мы работаем</a></li>
                        <li><a href="{{ route('about') }}" class="footer__link footer__link--arrow">Наши преимущества</a></li>
                        <li><a href="{{ route('payment') }}" class="footer__link footer__link--arrow">Оплата и доставка</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Возврат и обмен</a></li>
                        <li><a href="{{ route('faq') }}" class="footer__link footer__link--arrow">Вопросы и ответы</a></li>
                    </ul>
                </nav>

                <div class="footer__col">
                    <h3 class="footer__heading">Контакты</h3>
                    <a href="tel:+77777777777" class="footer__contact">
                        <img src="/img/icons/footer-call.svg" alt="" aria-hidden="true" width="21" height="21">
                        <span>+7 (777) 777-77-77</span>
                    </a>
                    <a href="mailto:info@example.ru" class="footer__contact">
                        <img src="/img/icons/footer-mail.svg" alt="" aria-hidden="true" width="21" height="21">
                        <span>Почта</span>
                    </a>
                    <div class="footer__socials">
                        <a href="#" class="footer__social" aria-label="ВКонтакте">
                            <img src="/img/icons/vk.svg" alt="" aria-hidden="true" width="24" height="15">
                        </a>
                        <a href="#" class="footer__social" aria-label="Telegram">
                            <img src="/img/icons/tg.svg" alt="" aria-hidden="true" width="20" height="16">
                        </a>
                    </div>
                </div>

                <div class="footer__col footer__col--subscribe">
                    <h3 class="footer__heading">Подписывайтесь на новости</h3>
                    <p class="footer__subscribe-text">
                        Будьте в курсе последних событий, акций и выгодных предложений
                    </p>
                    <a href="#" class="btn btn--primary footer__subscribe-btn">Подписаться</a>
                </div>
            </div>

            <p class="footer__legal">
                © 2026 ООО «АРТ ГРУПП»<br>
                ИНН: 7814593546 | ОГРН 1137847459936<br>
                192082, Россия, г. Санкт-Петербург, ул. Туристская, д. 23 к. 2
            </p>
        </div>
    </div>

    <div class="footer__mobile">
        <div class="container footer__mobile-top">
            <a href="/" class="footer__logo" aria-label="2POROGA — на главную">
                <img src="/img/logo-white.svg" alt="AVTOPOROGI.ru" width="258" height="39">
            </a>

            <h3 class="footer__heading footer__heading--center">Контакты для связи</h3>

            <div class="footer__mobile-contacts">
                <a href="tel:+77777777777" class="footer__contact">
                    <img src="/img/icons/footer-call.svg" alt="" aria-hidden="true" width="21" height="21">
                    <span>8 800 100 56 25</span>
                </a>
                <a href="mailto:info@example.ru" class="footer__contact">
                    <img src="/img/icons/footer-mail.svg" alt="" aria-hidden="true" width="21" height="21">
                    <span>Почта</span>
                </a>
            </div>

            <a href="#" class="btn btn--primary footer__mobile-call">Заказать звонок</a>

            <div class="footer__socials footer__socials--center">
                <a href="#" class="footer__social" aria-label="ВКонтакте">
                    <img src="/img/icons/vk.svg" alt="" aria-hidden="true" width="24" height="15">
                </a>
                <a href="#" class="footer__social" aria-label="Telegram">
                    <img src="/img/icons/tg.svg" alt="" aria-hidden="true" width="20" height="16">
                </a>
            </div>

            <div class="footer__mobile-cols">
                <nav class="footer__col" aria-label="Информация">
                    <h3 class="footer__heading">Информация</h3>
                    <ul class="footer__links">
                        <li><a href="{{ route('how') }}" class="footer__link footer__link--arrow">Как мы работаем</a></li>
                        <li><a href="{{ route('about') }}" class="footer__link footer__link--arrow">Наши преимущества</a></li>
                        <li><a href="{{ route('payment') }}" class="footer__link footer__link--arrow">Оплата и доставка</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Возврат и обмен</a></li>
                        <li><a href="{{ route('faq') }}" class="footer__link footer__link--arrow">Вопросы и ответы</a></li>
                        <li><a href="{{ route('partners') }}" class="footer__link footer__link--arrow">Сотрудничество</a></li>
                    </ul>
                </nav>

                <nav class="footer__col" aria-label="Документы">
                    <h3 class="footer__heading">Документы</h3>
                    <ul class="footer__links">
                        <li><a href="#" class="footer__link footer__link--arrow">Реквизиты</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Политика конфиденциальности</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Правила использования информации</a></li>
                        <li><a href="#" class="footer__link footer__link--arrow">Правила продажи</a></li>
                    </ul>
                </nav>
            </div>
        </div>

        <div class="footer__bottom">
            <div class="container">
                <p class="footer__legal footer__legal--center">
                    © 2026 ООО «АРТ ГРУПП» ИНН: 7814593546 | ОГРН 1137847459936<br>
                    192082, Россия, г. Санкт-Петербург, ул. Туристская, д. 23 к. 2
                </p>
                <p class="footer__offerta">Сайт не является офертой</p>
            </div>
        </div>
    </div>
</footer>
