@props(['storefront' => null, 'favoritesCount' => 0, 'cartCount' => 0])

@php
    $topLinks = $storefront?->navigationFor(\App\Enums\NavigationZone::HeaderTop) ?? [];
    $mainLinks = $storefront?->navigationFor(\App\Enums\NavigationZone::HeaderMain) ?? [];
@endphp

<header class="header">
    @if ($topLinks !== [])
        <div class="header__top">
            <div class="container header__top-inner">
                <nav class="header__utils" aria-label="Дополнительное меню">
                    @foreach ($topLinks as $link)
                        <a href="{{ $link->url }}" class="header__util-link"
                            @if ($link->openInNewTab) target="_blank" rel="noopener noreferrer" @endif>{{ $link->title }}</a>
                    @endforeach
                </nav>
            </div>
        </div>
    @endif

    <div class="header__bar">
        <div class="container header__bar-inner">
            <x-burger />

            <a href="{{ route('home') }}" class="header__logo" aria-label="{{ $storefront?->storeName ?? 'AVTOPOROGI.ru' }} — на главную">
                <img src="/img/logo.svg" alt="AVTOPOROGI.ru" width="253" height="33">
            </a>

            @if ($mainLinks !== [])
                <nav class="header__nav" aria-label="Основное меню">
                    <span class="header__nav-sep" aria-hidden="true"></span>
                    @foreach ($mainLinks as $link)
                        <a href="{{ $link->url }}" class="header__nav-link"
                            @if ($link->openInNewTab) target="_blank" rel="noopener noreferrer" @endif>{{ $link->title }}</a>
                    @endforeach
                    <span class="header__nav-sep" aria-hidden="true"></span>
                </nav>
            @endif

            <div class="header__left">
                @if ($storefront?->phoneUrl && $storefront?->phoneDisplay)
                    <a href="{{ $storefront->phoneUrl }}" class="header__phone">
                        <img class="header__phone-icon" src="/img/icons/header-call.svg" alt="" aria-hidden="true"
                            width="28" height="27">
                        <span class="header__phone-text">
                            <span class="header__phone-number">{{ $storefront->phoneDisplay }}</span>
                            @if ($storefront->phoneCaption)
                                <span class="header__phone-caption">{{ $storefront->phoneCaption }}</span>
                            @endif
                        </span>
                    </a>
                @endif

                <div class="header__actions">
                    <a
                        href="{{ route('favorites.show') }}"
                        class="header__action header__favorites"
                        aria-label="Избранное, товаров: {{ $favoritesCount }}"
                        data-favorites-link
                    >
                        <img src="/img/icons/header-heart.svg" alt="" aria-hidden="true" width="42" height="36">
                        <span
                            class="header__favorites-badge"
                            data-favorites-count
                            @if ($favoritesCount < 1) hidden @endif
                        >{{ $favoritesCount > 99 ? '99+' : $favoritesCount }}</span>
                    </a>
                    <a
                        href="{{ route('cart.show') }}"
                        class="header__action header__cart"
                        aria-label="Корзина, товаров: {{ $cartCount }}"
                        data-cart-link
                    >
                        <img src="/img/icons/header-cart.svg" alt="" aria-hidden="true" width="47" height="39">
                        <span
                            class="header__cart-badge"
                            data-cart-count
                            @if ($cartCount < 1) hidden @endif
                        >{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
                    </a>
                </div>
            </div>
        </div>

        <x-mobile-menu :storefront="$storefront" />
    </div>
</header>
