@extends('layouts.app')

@section('title', 'Страница не найдена — '.(($storefront ?? null)?->storeName ?? 'AVTOPOROGI.ru'))

@section('content')
    <section class="error-page">
        <div class="container error-page__body">
            <div class="error-page__art">
                <img class="error-page__art-layer error-page__art-layer--shadow" src="/img/errors/404-shadow.webp"
                    alt="" aria-hidden="true">
                <img class="error-page__art-layer" src="/img/errors/404-truck.webp"
                    alt="Эвакуатор увозит объёмные цифры 404">
            </div>

            <div class="error-page__content">
                <span class="error-page__badge">Ошибка 404</span>
                <h1 class="error-page__title">Страница не найдена</h1>
                <p class="error-page__text">
                    Похоже ссылка устарела, страница перемещена или адрес введён с ошибкой.
                </p>
                <div class="error-page__actions">
                    <a href="{{ route('home') }}" class="error-page__btn error-page__btn--primary">
                        Вернуться на главную
                    </a>
                    <a href="{{ route('catalog.index') }}" class="error-page__btn error-page__btn--outline">
                        Перейти в каталог
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
