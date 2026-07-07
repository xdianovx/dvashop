@extends('layouts.app')

@section('title', 'Модели автомобилей Alfa Romeo — 2POROGA')

@php
    $models = ['147', '159', '166', 'Brera', 'Mito', 'Корса'];
@endphp

@section('content')
    <section class="brand-page">
        <div class="container brand-page__body">
            <div class="brand-page__crumbs">
                <x-breadcrumbs :items="[
                    ['label' => 'Главная', 'url' => route('home')],
                    ['label' => 'Каталог', 'url' => route('catalog.index')],
                    ['label' => 'Alfa Romeo'],
                ]" />
            </div>

            <h1 class="brand-page__title">Модели автомобилей ALFA ROMEO</h1>

            <h2 class="brand-page__subtitle">Выберите модель</h2>

            <div class="brand-page__filters">
                <button type="button" class="brand-page__filter brand-page__filter--active">Все</button>
                <button type="button" class="brand-page__filter">Популярные</button>
            </div>

            <form class="brand-page__search" action="#" method="get">
                <input type="text" class="brand-page__search-input" placeholder="Поиск модели" name="q">
                <button type="submit" class="btn btn--primary brand-page__search-submit">Показать</button>
                <button type="submit" class="brand-page__search-icon" aria-label="Найти">
                    <img src="/img/brand-page/search.svg" alt="" aria-hidden="true">
                </button>
            </form>

            <ul class="brand-page__grid">
                @foreach ($models as $model)
                    <li class="brand-page__item">
                        <a href="{{ route('catalog.model') }}" class="brand-page__card">
                            <span class="brand-page__thumb"></span>
                            <span class="brand-page__name">{{ $model }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endsection
