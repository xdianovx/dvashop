@extends('layouts.app')

@section('title', 'Моя корзина — '.(($storefront ?? null)?->storeName ?? 'AVTOPOROGI.ru'))

@section('content')
    <div class="container cart-page">
        <x-breadcrumbs :items="[['label' => 'Главная', 'url' => route('home')], ['label' => 'Моя корзина']]" />
        <h1 class="cart-title">Моя корзина</h1>
        @if ($errors->any())<div class="cart-page__error" role="alert">{{ $errors->first() }}</div>@endif

        <div class="cart-content" data-cart-content>
            @if ($items->isEmpty())
                <x-cart-empty-state />
            @else
                @if ($hasUnavailablePrices)
                    <p class="cart-price-notice" role="alert">Для одного или нескольких товаров требуется уточнить цену.</p>
                @endif
                <div class="cart-layout">
                    <div class="cart-list" data-cart-list>@foreach ($items as $item)<x-cart-item :item="$item" />@endforeach</div>
                    <div class="cart-aside">
                        <x-cart-summary :totals="$totals" :has-unavailable-prices="$hasUnavailablePrices" />
                        <form class="cart-clear-form" action="{{ route('cart.clear') }}" method="post" data-cart-clear>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="cart-clear">Очистить корзину</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
        <template data-cart-empty-template><x-cart-empty-state /></template>
    </div>
@endsection
