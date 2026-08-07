<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $resolvedSeo = ($pageData ?? null)?->seo;
        $resolvedTitle = $resolvedSeo?->title ?? ($metaTitle ?? null);
        $resolvedDescription = $resolvedSeo?->description ?? ($metaDescription ?? null);
        $resolvedCanonical = $resolvedSeo?->canonical ?? ($canonicalUrl ?? null);
        $storefrontData = $storefront ?? null;
    @endphp
    <title>@if ($resolvedTitle){{ $resolvedTitle }}@else @yield('title', '2POROGA — кузовные пороги и арки') @endif</title>
    @if ($resolvedDescription)
        <meta name="description" content="{{ $resolvedDescription }}">
    @endif
    @if ($resolvedCanonical)
        <link rel="canonical" href="{{ $resolvedCanonical }}">
    @endif
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>

<body>
    <x-header :storefront="$storefrontData" />

    <main>
        @yield('content')
    </main>

    <x-footer :storefront="$storefrontData" />
    <x-mobile-nav :storefront="$storefrontData" />
</body>

</html>
