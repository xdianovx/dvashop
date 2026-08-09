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

        @if ($items->isNotEmpty())
            <ul class="brands">
                @foreach ($items as $item)
                    <li class="brands__item">
                        <a href="{{ $item['url'] }}" class="brand-card">
                            <span class="brand-card__logo"><img src="{{ $item['image'] }}" alt="{{ $item['title'] }}" loading="lazy"></span>
                            <span class="brand-card__name">{{ $item['title'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($products->isNotEmpty())
            <ul class="products">
                @foreach ($products as $product)
                    <li class="products__item"><x-product-card :product="$product" /></li>
                @endforeach
            </ul>
            @if ($products instanceof \Illuminate\Contracts\Pagination\Paginator)
                {{ $products->links() }}
            @endif
        @elseif ($searchQuery !== '' || request()->hasAny(['category', 'part_type']))
            <p>По вашему запросу товары не найдены.</p>
        @endif
    </div>
@endsection
