@extends('layouts.app')

@section('content')
    <div class="container favorites-page">
        <x-breadcrumbs :items="$breadcrumbs" />
        <x-section-heading class="section-heading--catalog" title="Избранное">
            <x-slot:icon>
                <img src="/img/icons/header-heart.svg" alt="" aria-hidden="true" width="42" height="36">
            </x-slot:icon>
        </x-section-heading>

        <div data-favorites-content>
            @if ($products->isEmpty())
                <div class="favorites-empty" data-favorites-empty>
                    <h2 class="favorites-empty__title">В избранном пока ничего нет</h2>
                    <p class="favorites-empty__text">Добавляйте товары сердечком, чтобы быстро вернуться к ним позже.</p>
                    <a href="{{ route('catalog.index') }}" class="btn btn--primary favorites-empty__action">Перейти в каталог</a>
                </div>
            @else
                <ul class="products favorites-page__products">
                    @foreach ($products as $product)
                        <li class="products__item" data-favorite-item="{{ $product->id }}">
                            <x-product-card :product="$product" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <template data-favorites-empty-template>
            <div class="favorites-empty" data-favorites-empty>
                <h2 class="favorites-empty__title">В избранном пока ничего нет</h2>
                <p class="favorites-empty__text">Добавляйте товары сердечком, чтобы быстро вернуться к ним позже.</p>
                <a href="{{ route('catalog.index') }}" class="btn btn--primary favorites-empty__action">Перейти в каталог</a>
            </div>
        </template>
    </div>
@endsection
