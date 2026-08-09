@extends('layouts.app')

@section('title', 'Моя корзина — 2POROGA')

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[['label' => 'Главная', 'url' => route('home')], ['label' => 'Моя корзина']]" />
        <h1 class="cart-title">Моя корзина</h1>
        @if ($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif

        @if ($items->isEmpty())
            <p>Корзина пуста.</p>
            <a href="{{ route('catalog.index') }}" class="btn btn--primary">Перейти в каталог</a>
        @else
            <div class="cart-layout">
                <div class="cart-list">@foreach ($items as $item)<x-cart-item :item="$item" />@endforeach</div>
                <x-cart-summary :count="$totals['items_count']" :subtotal="$totals['subtotal']" />
            </div>
        @endif
    </div>
@endsection
