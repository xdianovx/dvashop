@extends('layouts.app')

@section('title', 'Каталог марок — 2POROGA')

@php
    $brands = [
        'Acura', 'Alfa Romeo', 'Audi', 'BAW', 'Bentley', 'BMW',
        'Brilliance', 'BYD', 'Cadillac', 'Changan', 'Chery', 'Chevrolet',
        'Chrysler', 'Citroen', 'Dacia', 'Daewoo', 'DAF', 'Daihatsu',
        'Datsun', 'Derways', 'Dodge', 'Doninvest', 'FAW', 'Fiat',
        'Ford', 'Foton', 'Geely', 'Geo', 'GMC', 'Great Wall',
    ];
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Каталог']]" />

        <x-section-heading class="section-heading--catalog" title="Выберите марку">
            <x-slot:icon>
                <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="18" r="14" />
                    <path d="M38 38 L28 28" />
                </svg>
            </x-slot:icon>
        </x-section-heading>

        <div class="catalog-search">
            <form class="catalog-search__form" action="#" method="get">
                <input type="text" class="catalog-search__input"
                    placeholder="Введите марку модель или код автомобиля">
                <button type="submit" class="btn btn--primary catalog-search__submit">
                    <span class="catalog-search__submit-text">Показать</span>
                    <span class="catalog-search__submit-icon" aria-hidden="true">
                        <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="18" cy="18" r="14" />
                            <path d="M38 38 L28 28" />
                        </svg>
                    </span>
                </button>
            </form>

            <div class="catalog-search__tabs" role="tablist">
                <button type="button" class="catalog-tab catalog-tab--active">Все</button>
                <button type="button" class="catalog-tab">Коммерческие</button>
                <button type="button" class="catalog-tab">Популярные</button>
            </div>
        </div>

        <ul class="brands">
            @foreach ($brands as $brand)
                @php $slug = Str::slug($brand); @endphp
                <li class="brands__item">
                    <a href="#" class="brand-card">
                        <span class="brand-card__logo">
                            <img src="/img/brands/{{ $slug }}.svg" alt="{{ $brand }}" loading="lazy">
                        </span>
                        <span class="brand-card__name">{{ $brand }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
