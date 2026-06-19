@extends('layouts.app')

@section('title', 'Оформление заказа — 2POROGA')

@php
    $payments = [
        ['value' => 'card', 'icon' => '💳', 'title' => 'Банковская карта', 'desc' => 'онлайн после подтверждения', 'checked' => true],
        ['value' => 'sbp', 'icon' => '⚡', 'title' => 'СБП', 'desc' => 'Перевод по QR или ссылке'],
        ['value' => 'invoice', 'icon' => '📄', 'title' => 'Счёт для юрлиц', 'desc' => 'С НДС'],
        ['value' => 'cash', 'icon' => '🤝', 'title' => 'При получении', 'desc' => 'курьеру / на складе'],
    ];

    $order = [
        ['name' => 'Кузовной порог для Alfa Romeo 33 (1990–1994)', 'opts' => 'Оцинковка · 1 мм · правый', 'qty' => '2 шт. × 1 750 руб.', 'sum' => '3 500 руб.'],
        ['name' => 'Арка для Alfa Romeo 33 (1990–1994)', 'opts' => 'Оцинковка · 1 мм · правый', 'qty' => '1 шт. × 1 750 руб.', 'sum' => '1 750 руб.'],
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

        <div class="checkout-layout">
            <div class="checkout-main">
                <section class="checkout-card">
                    <header class="checkout-card__head">
                        <h2 class="checkout-card__title">Ваши данные</h2>
                        <span class="checkout-card__step">Шаг 1</span>
                    </header>
                    <form class="checkout-form">
                        <x-form-field class="checkout-form__full" label="ФИО" name="name" placeholder="Иван" :required="true" />
                        <x-form-field label="Телефон" name="phone" placeholder="+7 (___) ___‑__‑__" :required="true" />
                        <x-form-field label="Email" name="email" placeholder="mail@yandex.ru" />
                        <x-form-field label="Город" name="city" placeholder="Москва" :required="true" />
                        <x-form-field label="Адрес" name="address" placeholder="Улица, дом, квартира" :required="true" />
                        <x-form-field class="checkout-form__full" label="Комментарий к заказу" name="comment"
                            placeholder="Текст...." :textarea="true" />
                    </form>
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
                    @foreach ($order as $item)
                        <li class="checkout-order__item">
                            <span class="checkout-order__thumb">
                                <img src="/img/products/threshold.png" alt="" aria-hidden="true">
                            </span>
                            <div class="checkout-order__info">
                                <p class="checkout-order__name">{{ $item['name'] }}</p>
                                <p class="checkout-order__opts">{{ $item['opts'] }}</p>
                                <p class="checkout-order__qty">{{ $item['qty'] }}</p>
                            </div>
                            <span class="checkout-order__sum">{{ $item['sum'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="checkout-order__row">
                    <span>3 товара на сумму</span>
                    <span class="checkout-order__value">5 250 руб.</span>
                </div>
                <div class="checkout-order__row">
                    <span>Доставка</span>
                    <span class="checkout-order__value">700 руб.</span>
                </div>
                <div class="checkout-order__total">
                    <span>Итого</span>
                    <span class="checkout-order__total-value">5 950 руб.</span>
                </div>

                <button type="submit" class="btn checkout-order__submit">Заказать</button>

                <label class="checkout-order__agree">
                    <input type="checkbox" checked>
                    <span class="checkout-order__agree-box"></span>
                    <span class="checkout-order__agree-text">
                        Нажимая «Заказать», вы соглашаетесь на обработку персональных данных. Подробнее — в
                        <a href="#">Политике</a>.
                    </span>
                </label>
            </aside>
        </div>
    </div>
@endsection
