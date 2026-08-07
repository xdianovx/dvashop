@props(['storefront' => null])

@php
    $links = $storefront?->navigationFor(\App\Enums\NavigationZone::Mobile) ?? [];
@endphp

<div class="mobile-menu" data-mobile-menu>
    <div class="mobile-menu__head">
        <span class="mobile-menu__title">Меню</span>
        <button type="button" class="mobile-menu__close" data-mobile-menu-close>Свернуть</button>
    </div>
    <nav class="mobile-menu__nav" aria-label="Мобильное меню">
        @foreach ($links as $link)
            <a href="{{ $link->url }}" class="mobile-menu__link {{ $link->url === route('catalog.index') ? 'mobile-menu__link--catalog' : '' }}"
                @if ($link->openInNewTab) target="_blank" rel="noopener noreferrer" @endif>{{ $link->title }}</a>
        @endforeach
    </nav>
</div>
