<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $metaTitle ?? '2POROGA — кузовные пороги и арки')</title>
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
    @elseif (! empty($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
    @endif
    @if (! empty($canonicalUrl))
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @else
        <link rel="canonical" href="{{ url()->current() }}">
    @endif
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>

<body>
    <x-header />

    <main>
        @yield('content')
    </main>

    <x-footer />
</body>

</html>
