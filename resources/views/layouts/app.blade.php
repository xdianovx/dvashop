<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $resolvedSeo = ($pageData ?? null)?->seo ?? ($seo ?? null);
        $resolvedTitle = $resolvedSeo?->title ?? ($metaTitle ?? null);
        $resolvedDescription = $resolvedSeo?->description ?? ($metaDescription ?? null);
        $resolvedCanonical = $resolvedSeo?->canonical ?? ($canonicalUrl ?? null);
        $storefrontData = $storefront ?? null;
        $storeName = $storefrontData?->storeName ?? 'AVTOPOROGI.ru';
        $resolvedNoindex = $resolvedSeo?->noindex ?? ($noindex ?? false);
        $resolvedOgTitle = $resolvedSeo?->ogTitle ?? ($ogTitle ?? $resolvedTitle);
        $resolvedOgDescription = $resolvedSeo?->ogDescription ?? ($ogDescription ?? $resolvedDescription);
        $resolvedOgImage = $resolvedSeo?->ogImage ?? ($ogImage ?? null);
        $uisPublicKey = trim((string) config('shop.uis.public_key'));
        $uisPublicKey = preg_match('/\A[A-Za-z0-9_-]+\z/', $uisPublicKey) === 1 ? $uisPublicKey : null;
    @endphp
    <title>@if ($resolvedTitle){{ $resolvedTitle }}@else @yield('title', $storeName.' — кузовные пороги и арки') @endif</title>
    @if ($resolvedDescription)
        <meta name="description" content="{{ $resolvedDescription }}">
    @endif
    @if ($resolvedCanonical)
        <link rel="canonical" href="{{ $resolvedCanonical }}">
    @endif
    @if ($resolvedNoindex)
        <meta name="robots" content="noindex, nofollow">
    @endif
    @if ($resolvedOgTitle)
        <meta property="og:title" content="{{ $resolvedOgTitle }}">
    @endif
    @if ($resolvedOgDescription)
        <meta property="og:description" content="{{ $resolvedOgDescription }}">
    @endif
    @if ($resolvedOgImage)
        <meta property="og:image" content="{{ $resolvedOgImage }}">
    @endif
    @if ($uisPublicKey)
        <script async src="https://app.uiscom.ru/static/cs.min.js?k={{ rawurlencode($uisPublicKey) }}"></script>
    @endif
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>

<body>
    <x-storefront-loader />
    <x-header
        :storefront="$storefrontData"
        :favorites-count="$favoritesCount ?? 0"
        :cart-count="$cartCount ?? 0"
    />

    <main>
        @yield('content')
    </main>

    <x-footer :storefront="$storefrontData" />
    <x-mobile-nav :storefront="$storefrontData" />
    <x-storefront-toast />
    @if (session()->has('uis_success_payload'))
        <script type="application/json" data-uis-success-payload>{!! json_encode(session('uis_success_payload'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    @endif
    @stack('scripts')
</body>

</html>
