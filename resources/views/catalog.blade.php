@extends('layouts.app')

@if (! empty($metaDescription))
    @section('meta_description', $metaDescription)
@endif

@section('title', $pageTitle ?? 'Каталог марок — 2POROGA')

@section('content')
    <div class="container">
        <x-breadcrumbs :items="$breadcrumbs ?? [['label' => 'Главная', 'url' => '/'], ['label' => 'Каталог']]" />

        <x-section-heading class="section-heading--catalog" :title="$headingTitle ?? 'Выберите марку'">
            <x-slot:icon>
                <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="18" r="14" />
                    <path d="M38 38 L28 28" />
                </svg>
            </x-slot:icon>
        </x-section-heading>

        <div class="catalog-search">
            <form class="catalog-search__form" action="{{ route('catalog.index') }}" method="get">
                <input type="text" name="q" value="{{ $searchQuery ?? '' }}" class="catalog-search__input"
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
            @forelse ($items ?? [] as $item)
                <li class="brands__item">
                    <a href="{{ $item['url'] }}" class="brand-card">
                        <span class="brand-card__logo">
                            <img src="{{ $item['image'] }}" alt="{{ $item['title'] }}" loading="lazy">
                        </span>
                        <span class="brand-card__name">{{ $item['title'] }}</span>
                    </a>
                </li>
            @empty
                <li class="brands__item">
                    <span class="brand-card">
                        <span class="brand-card__name">Ничего не найдено</span>
                    </span>
                </li>
            @endforelse
        </ul>

        @if (($products ?? collect())->isNotEmpty())
            <ul class="products">
                @foreach ($products as $product)
                    <li class="products__item">
                        <x-product-card :card="$product" />
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
