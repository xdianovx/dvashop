@extends('layouts.app')

@section('title', 'Моя корзина — 2POROGA')

@php
    $money = static fn ($value) => number_format((float) $value, 0, ',', ' ') . ' руб.';
    $plural = static function (int $count): string {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $count . ' товар';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $count . ' товара';
        }

        return $count . ' товаров';
    };
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[['label' => 'Главная', 'url' => '/'], ['label' => 'Моя корзина']]" />

        <h1 class="cart-title">Моя корзина</h1>

        <div class="cart-layout">
            <div class="cart-list">
                @forelse ($items as $item)
                    @php
                        $lineTotal = (float) $item->price_snapshot * $item->quantity;
                        $options = collect($item->variant?->options ?? [])
                            ->map(fn ($value, $key) => is_string($key) ? $key . ': ' . $value : $value)
                            ->filter()
                            ->implode(' • ');
                    @endphp

                    <x-cart-item
                        :item="$item"
                        :name="$item->title_snapshot"
                        :options="$options ?: ($item->variant?->title ?? '')"
                        :qty="$item->quantity"
                        :price="$money($lineTotal)"
                        :unit="$money($item->price_snapshot) . ' за шт.'"
                    />
                @empty
                    <p>Корзина пока пуста.</p>
                @endforelse
            </div>

            <x-cart-summary
                :count="$plural($totals['items_count'])"
                :subtotal="$money($totals['subtotal'])"
                :total="$money($totals['subtotal'])"
            />
        </div>
    </div>
@endsection
