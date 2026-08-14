@props(['storefront' => null])

@php
    $aboutLinks = [
        ...($storefront?->navigationFor(\App\Enums\NavigationZone::FooterAbout) ?? []),
        ...($storefront?->navigationFor(\App\Enums\NavigationZone::FooterDocuments) ?? []),
    ];
    $legalDocuments = $storefront?->legalDocuments ?? [];
    $socials = $storefront?->socials ?? [];
    $hasRequisites = $storefront && collect([
        $storefront->footerCopyright,
        $storefront->legalName,
        $storefront->inn,
        $storefront->ogrn,
        $storefront->legalAddress,
    ])->filter()->isNotEmpty();
@endphp

<footer class="footer">
    <div class="footer__desktop">
        <div class="container">
            <div class="footer__inner">
                <div class="footer__col footer__col--brand">
                    <a href="{{ route('home') }}" class="footer__logo" aria-label="{{ $storefront?->storeName ?? 'AVTOPOROGI.ru' }} — на главную">
                        <img src="/img/logo-white.svg" alt="AVTOPOROGI.ru" width="258" height="39">
                    </a>

                    @if ($legalDocuments !== [])
                        <ul class="footer__links">
                            @foreach ($legalDocuments as $link)
                                <li><a href="{{ $link->url }}" class="footer__link footer__link--arrow">{{ $link->title }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($aboutLinks !== [])
                    <nav class="footer__col" aria-label="О нас">
                        <h3 class="footer__heading">О нас</h3>
                        <ul class="footer__links">
                            @foreach ($aboutLinks as $link)
                                <li>
                                    <a href="{{ $link->url }}" class="footer__link footer__link--arrow"
                                        @if ($link->openInNewTab) target="_blank" rel="noopener noreferrer" @endif>{{ $link->title }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endif

                @if ($storefront?->phoneUrl || $storefront?->emailUrl || $socials !== [] || $storefront?->workHours)
                    <div class="footer__col">
                        <h3 class="footer__heading">Контакты</h3>
                        @if ($storefront?->phoneUrl && $storefront?->phoneDisplay)
                            <a href="{{ $storefront->phoneUrl }}" class="footer__contact">
                                <img src="/img/icons/footer-call.svg" alt="" aria-hidden="true" width="21" height="21">
                                <span>{{ $storefront->phoneDisplay }}</span>
                            </a>
                        @endif
                        @if ($storefront?->emailUrl && $storefront?->publicEmail)
                            <a href="{{ $storefront->emailUrl }}" class="footer__contact">
                                <img src="/img/icons/footer-mail.svg" alt="" aria-hidden="true" width="21" height="21">
                                <span>{{ $storefront->publicEmail }}</span>
                            </a>
                        @endif
                        @if ($storefront?->workHours)
                            <p class="footer__subscribe-text">{{ $storefront->workHours }}</p>
                        @endif
                        @if ($socials !== [])
                            <div class="footer__socials">
                                @foreach ($socials as $social)
                                    <a href="{{ $social['url'] }}" class="footer__social" aria-label="{{ $social['label'] }}"
                                        target="_blank" rel="noopener noreferrer">
                                        <img src="{{ $social['code'] === 'vk' ? '/img/icons/vk.svg' : '/img/icons/tg.svg' }}"
                                            alt="" aria-hidden="true">
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if ($hasRequisites)
                <p class="footer__legal">
                    @if ($storefront->footerCopyright)
                        {{ $storefront->footerCopyright }}<br>
                    @elseif ($storefront->legalName)
                        {{ $storefront->legalName }}<br>
                    @endif
                    @if ($storefront->inn)
                        ИНН: {{ $storefront->inn }}@if ($storefront->ogrn) | ОГРН {{ $storefront->ogrn }}@endif<br>
                    @elseif ($storefront->ogrn)
                        ОГРН: {{ $storefront->ogrn }}<br>
                    @endif
                    @if ($storefront->legalAddress)
                        {{ $storefront->legalAddress }}
                    @endif
                </p>
            @endif
        </div>
    </div>

    <div class="footer__mobile">
        <div class="container footer__mobile-top">
            <a href="{{ route('home') }}" class="footer__logo" aria-label="{{ $storefront?->storeName ?? 'AVTOPOROGI.ru' }} — на главную">
                <img src="/img/logo-white.svg" alt="AVTOPOROGI.ru" width="258" height="39">
            </a>

            @if ($storefront?->phoneUrl || $storefront?->emailUrl)
                <h3 class="footer__heading footer__heading--center">Контакты для связи</h3>
                <div class="footer__mobile-contacts">
                    @if ($storefront?->phoneUrl && $storefront?->phoneDisplay)
                        <a href="{{ $storefront->phoneUrl }}" class="footer__contact">
                            <img src="/img/icons/footer-call.svg" alt="" aria-hidden="true" width="21" height="21">
                            <span>{{ $storefront->phoneDisplay }}</span>
                        </a>
                    @endif
                    @if ($storefront?->emailUrl && $storefront?->publicEmail)
                        <a href="{{ $storefront->emailUrl }}" class="footer__contact">
                            <img src="/img/icons/footer-mail.svg" alt="" aria-hidden="true" width="21" height="21">
                            <span>{{ $storefront->publicEmail }}</span>
                        </a>
                    @endif
                </div>
            @endif

            @if ($socials !== [])
                <div class="footer__socials footer__socials--center">
                    @foreach ($socials as $social)
                        <a href="{{ $social['url'] }}" class="footer__social" aria-label="{{ $social['label'] }}"
                            target="_blank" rel="noopener noreferrer">
                            <img src="{{ $social['code'] === 'vk' ? '/img/icons/vk.svg' : '/img/icons/tg.svg' }}"
                                alt="" aria-hidden="true">
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($aboutLinks !== [] || $legalDocuments !== [])
                <div class="footer__mobile-cols">
                    @if ($aboutLinks !== [])
                        <nav class="footer__col" aria-label="Информация">
                            <h3 class="footer__heading">Информация</h3>
                            <ul class="footer__links">
                                @foreach ($aboutLinks as $link)
                                    <li>
                                        <a href="{{ $link->url }}" class="footer__link footer__link--arrow"
                                            @if ($link->openInNewTab) target="_blank" rel="noopener noreferrer" @endif>{{ $link->title }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif

                    @if ($legalDocuments !== [])
                        <nav class="footer__col" aria-label="Документы">
                            <h3 class="footer__heading">Документы</h3>
                            <ul class="footer__links">
                                @foreach ($legalDocuments as $link)
                                    <li><a href="{{ $link->url }}" class="footer__link footer__link--arrow">{{ $link->title }}</a></li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif
                </div>
            @endif
        </div>

        @if ($hasRequisites || $storefront?->footerDisclaimer)
            <div class="footer__bottom">
                <div class="container">
                    @if ($hasRequisites)
                        <p class="footer__legal footer__legal--center">
                            {{ $storefront->footerCopyright ?: $storefront->legalName }}
                            @if ($storefront->inn) ИНН: {{ $storefront->inn }}@endif
                            @if ($storefront->ogrn) | ОГРН {{ $storefront->ogrn }}@endif
                            @if ($storefront->legalAddress)<br>{{ $storefront->legalAddress }}@endif
                        </p>
                    @endif
                    @if ($storefront?->footerDisclaimer)
                        <p class="footer__offerta">{{ $storefront->footerDisclaimer }}</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</footer>
