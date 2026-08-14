@extends('layouts.app')

@section('title', 'Заказ оформлен — '.(($storefront ?? null)?->storeName ?? 'AVTOPOROGI.ru'))

@section('content')
    <section class="thanks">
        <div class="container">
            <x-breadcrumbs :items="[['label' => 'Главная', 'url' => route('home')], ['label' => 'Заказ оформлен']]" />
            <div class="thanks__hero">
                <span class="thanks__check" aria-hidden="true">✓</span><span class="thanks__status">Заказ принят</span>
                <h1 class="thanks__title">Спасибо за заказ!</h1>
                <p class="thanks__lead">{{ $order->customer_name }}, заказ успешно оформлен. Мы свяжемся с вами по телефону {{ $order->customer_phone }} для подтверждения.</p>
            </div>

            <div class="thanks__layout">
                <section class="thanks-details">
                    <header class="thanks-details__head"><h2 class="thanks-details__title">Детали заказа</h2></header>
                    <p class="thanks-details__number"><span class="thanks-details__number-label">Номер заказа</span><span class="thanks-details__number-value">{{ $order->number }}</span></p>
                    <dl class="thanks-details__list">
                        <div class="thanks-details__row"><dt class="thanks-details__term">Получатель</dt><dd class="thanks-details__value">{{ $order->customer_name }}</dd></div>
                        <div class="thanks-details__row"><dt class="thanks-details__term">Телефон</dt><dd class="thanks-details__value">{{ $order->customer_phone }}</dd></div>
                        @if ($order->customer_email)<div class="thanks-details__row"><dt class="thanks-details__term">Email</dt><dd class="thanks-details__value">{{ $order->customer_email }}</dd></div>@endif
                        @if ($order->customer_city)<div class="thanks-details__row"><dt class="thanks-details__term">Город</dt><dd class="thanks-details__value">{{ $order->customer_city }}</dd></div>@endif
                        @if ($order->customer_address)<div class="thanks-details__row thanks-details__row--full"><dt class="thanks-details__term">Адрес</dt><dd class="thanks-details__value">{{ $order->customer_address }}</dd></div>@endif
                        <div class="thanks-details__row thanks-details__row--full"><dt class="thanks-details__term">Доставка</dt><dd class="thanks-details__value">{{ $order->delivery_method_title_snapshot ?: 'Не указана' }}</dd></div>
                        <div class="thanks-details__row thanks-details__row--full"><dt class="thanks-details__term">Оплата</dt><dd class="thanks-details__value">{{ $order->payment_method_title_snapshot ?: 'Не указана' }}</dd></div>
                        @if ($order->customer_comment)<div class="thanks-details__row thanks-details__row--full"><dt class="thanks-details__term">Комментарий</dt><dd class="thanks-details__value">{{ $order->customer_comment }}</dd></div>@endif
                    </dl>
                    <ol class="thanks-steps">
                        @foreach ([
                            ['title' => 'Подтверждение', 'text' => 'Менеджер перезвонит и уточнит детали заказа и сроки отгрузки.'],
                            ['title' => 'Комплектация', 'text' => 'Детали проверяются по геометрии и упаковываются для отправки.'],
                            ['title' => 'Доставка', 'text' => 'После отправки сообщим трек-номер по телефону или email.'],
                        ] as $index => $step)
                            <li class="thanks-steps__item">
                                <span class="thanks-steps__num" aria-hidden="true">{{ $index + 1 }}</span>
                                <span class="thanks-steps__title">{{ $step['title'] }}</span>
                                <p class="thanks-steps__text">{{ $step['text'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>

                <aside class="checkout-order thanks__summary">
                    <h2 class="checkout-order__title">Ваши товары</h2>
                    <ul class="checkout-order__list">
                        @foreach ($order->items as $item)
                            <li class="checkout-order__item">
                                <span class="checkout-order__thumb"><img src="{{ $item->image_snapshot }}" alt="" aria-hidden="true"></span>
                                <div class="checkout-order__info"><p class="checkout-order__name">{{ $item->title_snapshot }}</p>@if ($item->optionSummary())<p class="checkout-order__opts">{{ $item->optionSummary() }}</p>@endif<p class="checkout-order__qty">{{ $item->quantity }} шт. × {{ number_format((float) $item->price_snapshot, 0, ',', ' ') }} ₽</p></div>
                                <span class="checkout-order__sum">{{ number_format($item->lineTotal(), 0, ',', ' ') }} ₽</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="checkout-order__row"><span>Товары</span><span class="checkout-order__value">{{ number_format((float) $order->subtotal, 0, ',', ' ') }} ₽</span></div>
                    <div class="checkout-order__row"><span>Доставка</span><span class="checkout-order__value">{{ $order->deliveryPriceText() }}</span></div>
                    <div class="checkout-order__total"><span>{{ $order->total_is_final ? 'Итого' : 'Сумма товаров (без доставки)' }}</span><span class="checkout-order__total-value">{{ number_format((float) $order->total, 0, ',', ' ') }} ₽</span></div>
                </aside>
            </div>
            <div class="thanks__actions"><a href="{{ route('home') }}" class="thanks__btn thanks__btn--primary">Вернуться на главную</a><a href="{{ route('catalog.index') }}" class="thanks__btn thanks__btn--outline">Продолжить покупки</a></div>
        </div>
    </section>
@endsection
