@extends('layouts.app')

@section('title', 'Заказ оформлен — 2POROGA')

@php
    $details = [
        ['label' => 'Получатель', 'value' => '—', 'half' => true],
        ['label' => 'Телефон', 'value' => '—', 'half' => true],
        ['label' => 'Email', 'value' => 'pavel@gmail.com', 'half' => true],
        ['label' => 'Город', 'value' => 'Санкт-Петербург', 'half' => true],
        ['label' => 'Доставка', 'value' => 'Транспортная компания'],
        ['label' => 'Адрес', 'value' => 'Петрова, 23'],
        ['label' => 'Оплата', 'value' => 'Банковская карта'],
        ['label' => 'Комментарий', 'value' => 'Хочу много деталей'],
    ];

    $steps = [
        ['title' => 'Подтверждение', 'text' => 'Менеджер перезвонит и уточнит детали заказа и сроки отгрузки.'],
        ['title' => 'Комплектация', 'text' => 'Детали проверяются по геометрии и упаковываются для отправки.'],
        ['title' => 'Доставка', 'text' => 'Отправим ТК или курьером — трек-номер пришлём в SMS или на email.'],
    ];

    $order = [
        [
            'name' => 'Кузовной порог для Alfa Romeo 33 (1990–1994)',
            'opts' => 'Оцинковка · 1 мм · правый',
            'qty' => '2 шт. × 1 750 руб.',
            'sum' => '3 500 руб.',
        ],
        [
            'name' => 'Арка для Alfa Romeo 33 (1990–1994)',
            'opts' => 'Оцинковка · 1 мм · правый',
            'qty' => '1 шт. × 1 750 руб.',
            'sum' => '1 750 руб.',
        ],
    ];
@endphp

@section('content')
    <section class="thanks">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'Корзина', 'url' => route('cart.show')],
                ['label' => 'Заказ оформлен'],
            ]" />

            <div class="thanks__hero">
                <span class="thanks__check" aria-hidden="true">✓</span>
                <span class="thanks__status">Заказ принят</span>
                <h1 class="thanks__title">Спасибо за заказ!</h1>
                <p class="thanks__lead">
                    Павел Шабалин, ваш заказ успешно оформлен. Мы свяжемся с вами по телефону +7(911)669-34-97
                    для подтверждения в течение 15 минут в рабочее время
                </p>
            </div>

            <div class="thanks__layout">
                <section class="thanks-details">
                    <header class="thanks-details__head">
                        <h2 class="thanks-details__title">Детали заказа</h2>
                    </header>

                    <p class="thanks-details__number">
                        <span class="thanks-details__number-label">Номер заказа</span>
                        <span class="thanks-details__number-value">AP-260623-5058</span>
                    </p>

                    <dl class="thanks-details__list">
                        @foreach ($details as $detail)
                            <div
                                class="thanks-details__row @if (!($detail['half'] ?? false)) thanks-details__row--full @endif">
                                <dt class="thanks-details__term">{{ $detail['label'] }}</dt>
                                <dd class="thanks-details__value">{{ $detail['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <ol class="thanks-steps">
                        @foreach ($steps as $i => $step)
                            <li class="thanks-steps__item">
                                <span class="thanks-steps__num" aria-hidden="true">{{ $i + 1 }}</span>
                                <span class="thanks-steps__title">{{ $step['title'] }}</span>
                                <p class="thanks-steps__text">{{ $step['text'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>

                <aside class="checkout-order thanks__summary">
                    <h2 class="checkout-order__title">Ваши товары</h2>

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
                </aside>
            </div>

            <div class="thanks__actions">
                <a href="{{ route('home') }}" class="thanks__btn thanks__btn--primary">Вернуться на главную</a>
                <a href="{{ route('catalog.index') }}" class="thanks__btn thanks__btn--outline">Продолжить покупки</a>
            </div>
        </div>
    </section>
@endsection
