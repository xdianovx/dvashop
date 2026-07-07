<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '2POROGA — кузовные пороги и арки')</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>

<body>
    <x-header />

    <main>
        @yield('content')
    </main>

    <x-footer />
    <x-mobile-nav />
</body>

</html>
