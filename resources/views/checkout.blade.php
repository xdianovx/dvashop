@extends('layouts.app')

@section('title', 'Оформление заказа — 2POROGA')

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

    $payments = [
        ['value' => 'manager', 'icon' => '🤝', 'title' => 'После подтверждения', 'desc' => 'менеджер согласует оплату и доставку', 'checked' => true],
    ];
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[
            ['label' => 'Главная', 'url' => '/'],
            ['label' => 'Моя корзина', 'url' => '/cart'],
            ['label' => 'Оформление заказа'],
        ]" />

        <h1 class="checkout-title">Оформление заказа</h1>

        @if (session('order_created'))
            <p>{{ session('order_created') }}</p>
        @endif

        @error('cart')
            <p>{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('checkout.store') }}" class="checkout-layout">
            @csrf

            <div class="checkout-main">
                <section class="checkout-card">
                    <header class="checkout-card__head">
                        <h2 class="checkout-card__title">Ваши данные</h2>
                        <span class="checkout-card__step">Шаг 1</span>
                    </header>
                    <div class="checkout-form">
                        <x-form-field class="checkout-form__full" label="ФИО" name="name" placeholder="Иван" :required="true" />
                        <x-form-field label="Телефон" name="phone" placeholder="+7 (___) ___‑__‑__" :required="true" />
                        <x-form-field label="Email" name="email" placeholder="mail@yandex.ru" />
                        <x-form-field label="Город" name="city" placeholder="Москва" />
                        <x-form-field label="Адрес" name="address" placeholder="Улица, дом, квартира" />
                        <x-form-field class="checkout-form__full" label="Комментарий к заказу" name="comment"
                            placeholder="Текст...." :textarea="true" />
                    </div>
                </section>

                <section class="checkout-card">
                    <header class="checkout-card__head">
                        <h2 class="checkout-card__title">Оплата</h2>
                        <span class="checkout-card__step">Шаг 2</span>
                    </header>
                    <div class="checkout-payments">
                        @foreach ($payments as $p)
                            <x-payment-method :value="$p['value']" :icon="$p['icon']" :title="$p['title']"
                                :desc="$p['desc']" :checked="$p['checked'] ?? false" />
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="checkout-order">
                <h2 class="checkout-order__title">Ваш заказ</h2>

                <ul class="checkout-order__list">
                    @forelse ($items as $item)
                        @php
                            $options = collect($item->variant?->options ?? [])
                                ->map(fn ($value, $key) => is_string($key) ? $key . ': ' . $value : $value)
                                ->filter()
                                ->implode(' • ');
                            $lineTotal = (float) $item->price_snapshot * $item->quantity;
                        @endphp

                        <li class="checkout-order__item">
                            <span class="checkout-order__thumb">
                                <img src="/img/products/threshold.png" alt="" aria-hidden="true">
                            </span>
                            <div class="checkout-order__info">
                                <p class="checkout-order__name">{{ $item->title_snapshot }}</p>
                                <p class="checkout-order__opts">{{ $options ?: ($item->variant?->title ?? '') }}</p>
                                <p class="checkout-order__qty">{{ $item->quantity }} шт. × {{ $money($item->price_snapshot) }}</p>
                            </div>
                            <span class="checkout-order__sum">{{ $money($lineTotal) }}</span>
                        </li>
                    @empty
                        <li class="checkout-order__item">
                            <div class="checkout-order__info">
                                <p class="checkout-order__name">Корзина пока пуста.</p>
                            </div>
                        </li>
                    @endforelse
                </ul>

                <div class="checkout-order__row">
                    <span>{{ $plural($totals['items_count']) }} на сумму</span>
                    <span class="checkout-order__value">{{ $money($totals['subtotal']) }}</span>
                </div>
                <div class="checkout-order__row">
                    <span>Доставка</span>
                    <span class="checkout-order__value">по согласованию</span>
                </div>
                <div class="checkout-order__total">
                    <span>Итого</span>
                    <span class="checkout-order__total-value">{{ $money($totals['subtotal']) }}</span>
                </div>

                <button type="submit" class="btn checkout-order__submit" @disabled($items->isEmpty())>Заказать</button>

                <label class="checkout-order__agree">
                    <input type="checkbox" checked>
                    <span class="checkout-order__agree-box"></span>
                    <span class="checkout-order__agree-text">
                        Нажимая «Заказать», вы соглашаетесь на обработку персональных данных. Подробнее — в
                        <a href="#">Политике</a>.
                    </span>
                </label>
            </aside>
        </form>
    </div>
@endsection
