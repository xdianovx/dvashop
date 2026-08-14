@extends('layouts.app')

@section('content')
    <div class="container">
        <x-breadcrumbs :items="$breadcrumbs" />
        <div class="product-head">
            <span class="product-head__thumb"><img src="{{ $generationImage }}" alt="{{ $generation->display_title }}" loading="lazy"></span>
            <div class="product-head__info">
                <h1 class="product-head__title">{{ $headingTitle }}</h1>
                <p class="product-head__meta">{{ collect([$generation->body, $generation->years_label, $generation->title])->filter()->implode(' · ') }}</p>
            </div>
        </div>

        <form class="car-search" action="{{ route('catalog.generation', [$make->slug, $model->slug, $generation->slug]) }}" method="get">
            @if ($selectedCategory)<input type="hidden" name="category" value="{{ $selectedCategory->full_slug }}">@endif
            @if ($selectedPartType)<input type="hidden" name="part_type" value="{{ $selectedPartType->full_slug }}">@endif
            <input type="search" name="q" value="{{ $searchQuery }}" class="car-search__input" placeholder="Поиск: порог, усилитель, заглушка…">
            <button type="submit" class="btn btn--primary car-search__submit">Показать</button>
        </form>

        <div class="product-layout">
            <div class="catalog-dropdown">
                <button type="button" class="catalog-trigger" data-catalog-open aria-expanded="false"><span>Категории</span></button>
                <aside class="catalog-nav" data-catalog-nav>
                    <div class="catalog-nav__panel">
                        <div class="catalog-nav__bar"><span class="catalog-nav__title">Категории</span><button type="button" class="catalog-nav__toggle" data-catalog-toggle aria-expanded="true">Свернуть</button></div>
                        <ul class="catalog-nav__list">
                            <li><a href="{{ route('catalog.generation', [$make->slug, $model->slug, $generation->slug]) }}" class="catalog-nav__link catalog-nav__link--all @if (! $selectedCategory) catalog-nav__link--active @endif">Все элементы</a></li>
                            @foreach ($categories as $category)
                                <li><a href="{{ route('catalog.generation', [$make->slug, $model->slug, $generation->slug, 'category' => $category->full_slug]) }}" class="catalog-nav__link @if ($selectedCategory?->is($category)) catalog-nav__link--active @endif">{{ $category->title }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                </aside>
            </div>

            <div>
                <ul class="products">
                    @forelse ($products as $product)
                        <li class="products__item"><x-product-card :product="$product" /></li>
                    @empty
                        <li>Для выбранного автомобиля товары не найдены.</li>
                    @endforelse
                </ul>
                <x-storefront-pagination :paginator="$products" />
            </div>
        </div>
        <x-storefront-seo-text :text="$seoText ?? null" />
    </div>
@endsection
