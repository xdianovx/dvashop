@extends('layouts.app')

@if (! empty($metaDescription))
    @section('meta_description', $metaDescription)
@endif

@section('title', $pageTitle ?? 'Кузовные элементы — 2POROGA')

@section('content')
    <div class="container">
        <x-breadcrumbs :items="$breadcrumbs" />

        <div class="product-head">
            <span class="product-head__thumb">
                <img src="{{ $generation->image ?: '/img/cars/golf-5-plus.png' }}" alt="{{ $make->title }} {{ $model->title }}" loading="lazy">
            </span>
            <div class="product-head__info">
                <h1 class="product-head__title">{!! $headingTitle !!}</h1>
                <p class="product-head__meta">
                    {{ collect([$generation->body, $generation->title, $generation->years_label])->filter()->implode(' • ') }}
                </p>
            </div>
        </div>

        <form class="car-search" action="{{ route('catalog.generation', [$make->slug, $model->slug, $generation->slug]) }}" method="get">
            @if ($selectedCategory)
                <input type="hidden" name="category" value="{{ $selectedCategory->full_slug }}">
            @endif
            <input type="text" name="q" value="{{ $searchQuery ?? '' }}" class="car-search__input" placeholder="Поиск: порог, усилитель, заглушка…">
            <button type="submit" class="btn btn--primary car-search__submit">Показать</button>
        </form>

        <div class="product-layout">
            <button type="button" class="catalog-trigger" data-catalog-open aria-haspopup="dialog">
                <span>Категории</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </button>

            <aside class="catalog-nav" data-catalog-nav>
                <div class="catalog-nav__panel">
                    <div class="catalog-nav__bar">
                        <span class="catalog-nav__title">Категории</span>
                        <button type="button" class="catalog-nav__toggle" data-catalog-toggle aria-expanded="true">
                            Свернуть
                        </button>
                    </div>
                    <ul class="catalog-nav__list">
                        <li>
                            <a href="{{ route('catalog.generation', [$make->slug, $model->slug, $generation->slug]) }}" class="catalog-nav__link catalog-nav__link--all @if (! $selectedCategory) catalog-nav__link--active @endif">
                                Все элементы
                            </a>
                        </li>
                        @foreach ($categories as $category)
                            <li>
                                <a href="{{ route('catalog.generation', [$make->slug, $model->slug, $generation->slug]) }}?category={{ urlencode($category->full_slug) }}" class="catalog-nav__link @if ($selectedCategory?->is($category)) catalog-nav__link--active @endif">
                                    {{ $category->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </aside>

            <ul class="products">
                @forelse ($products as $product)
                    <li class="products__item">
                        <x-product-card :card="$product" />
                    </li>
                @empty
                    <li class="products__item">Товары не найдены</li>
                @endforelse
            </ul>
        </div>
    </div>
@endsection
