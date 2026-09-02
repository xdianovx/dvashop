@extends('layouts.app')

@section('content')
    <div class="container">
        <x-breadcrumbs :items="$breadcrumbs" />
        <x-section-heading class="section-heading--catalog" :title="$headingTitle">
            <x-slot:icon>
                <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="18" r="14" /><path d="M38 38 L28 28" />
                </svg>
            </x-slot:icon>
        </x-section-heading>

        <div class="catalog-search">
            <form class="catalog-search__form" action="{{ route('catalog.index') }}" method="get">
                @if (request('category'))<input type="hidden" name="category" value="{{ request('category') }}">@endif
                @if (request('part_type'))<input type="hidden" name="part_type" value="{{ request('part_type') }}">@endif
                <input type="search" name="q" value="{{ $searchQuery }}" class="catalog-search__input" placeholder="Введите марку, модель, товар или артикул">
                <button type="submit" class="btn btn--primary catalog-search__submit">
                    <span class="catalog-search__submit-text">Показать</span>
                    <span class="catalog-search__submit-icon" aria-hidden="true">
                        <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="18" cy="18" r="14" />
                            <path d="M38 38 L28 28" />
                        </svg>
                    </span>
                </button>
            </form>
        </div>

        <div class="catalog-results">
            @if ($vehicleMakes->isNotEmpty())
                <section
                    class="catalog-results__section"
                    data-search-group="makes"
                    @if ($searchQuery !== '') aria-labelledby="catalog-makes-title" @else aria-label="Марки" @endif
                >
                    @if ($searchQuery !== '')<h2 id="catalog-makes-title" class="catalog-results__title">Марки</h2>@endif
                    <ul class="brands">
                        @foreach ($vehicleMakes as $make)
                            <li class="brands__item">
                                <a href="{{ $make['url'] }}" class="brand-card">
                                    <span class="brand-card__logo"><img src="{{ $make['image'] }}" alt="{{ $make['title'] }}" loading="lazy"></span>
                                    <span class="brand-card__name">{{ $make['title'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($vehicleModels->isNotEmpty())
                <section class="catalog-results__section" data-search-group="models" aria-labelledby="catalog-models-title">
                    <h2 id="catalog-models-title" class="catalog-results__title">Модели</h2>
                    <ul class="model-grid catalog-results__model-grid">
                        @foreach ($vehicleModels as $model)
                            <li>
                                <x-model-card
                                    :href="$model['url']"
                                    :name="$model['model_title']"
                                    :sub="$model['make_title']"
                                    :img="$model['image']"
                                    variant="model"
                                />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($vehicleGenerations->isNotEmpty())
                <section class="catalog-results__section" data-search-group="generations" aria-labelledby="catalog-generations-title">
                    <h2 id="catalog-generations-title" class="catalog-results__title">Поколения</h2>
                    <ul class="model-grid catalog-results__model-grid">
                        @foreach ($vehicleGenerations as $generation)
                            <li>
                                <x-model-card
                                    :href="$generation['url']"
                                    :name="collect([$generation['title'], $generation['body']])->filter()->implode(' · ')"
                                    :sub="collect([$generation['make_title'].' '.$generation['model_title'], $generation['years_label']])->filter()->implode(' · ')"
                                    :img="$generation['image']"
                                    variant="body"
                                />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($products->isNotEmpty())
                <section class="catalog-results__section" data-search-group="products" aria-labelledby="catalog-products-title">
                    <h2 id="catalog-products-title" class="catalog-results__title">Детали и товары</h2>
                    <ul class="products">
                        @foreach ($products as $product)
                            <li class="products__item"><x-product-card :product="$product" /></li>
                        @endforeach
                    </ul>
                    @if ($products instanceof \Illuminate\Contracts\Pagination\Paginator)
                        <x-storefront-pagination :paginator="$products" />
                    @endif
                </section>
            @elseif ($searchQuery !== '' && $vehicleMakes->isEmpty() && $vehicleModels->isEmpty() && $vehicleGenerations->isEmpty())
                <p class="catalog-results__empty">По вашему запросу ничего не найдено.</p>
            @elseif (request()->hasAny(['category', 'part_type']))
                <p class="catalog-results__empty">По вашему запросу товары не найдены.</p>
            @endif
        </div>

        <x-storefront-seo-text :text="$seoText ?? null" />
    </div>
@endsection
