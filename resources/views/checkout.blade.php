@extends('layouts.app')

@section('title', 'Оформление заказа — '.(($storefront ?? null)?->storeName ?? 'AVTOPOROGI.ru'))

@section('content')
    <div class="container">
        <x-breadcrumbs :items="[['label' => 'Главная', 'url' => route('home')], ['label' => 'Моя корзина', 'url' => route('cart.show')], ['label' => 'Оформление заказа']]" />
        <h1 class="checkout-title">Оформление заказа</h1>
        @if ($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif

        @if ($items->isEmpty())
            <p>Корзина пуста. Добавьте товары перед оформлением заказа.</p>
            <a href="{{ route('catalog.index') }}" class="btn btn--primary">Перейти в каталог</a>
        @elseif ($deliveryMethods->isEmpty() || $paymentMethods->isEmpty())
            <p role="alert">Оформление заказа временно недоступно. Свяжитесь с нами для уточнения условий.</p>
        @else
            <x-promo-code-form :totals="$totals" class="checkout-promo" />
            <form class="checkout-layout" action="{{ route('checkout.store') }}" method="post">
                @csrf
                <div class="checkout-main">
                    <section class="checkout-card">
                        <header class="checkout-card__head"><h2 class="checkout-card__title">Ваши данные</h2><span class="checkout-card__step">Шаг 1</span></header>
                        <div class="checkout-form">
                            <x-form-field class="checkout-form__full" label="ФИО" name="customer_name" placeholder="Иванов Иван Иванович" :required="true" />
                            <x-form-field label="Телефон" name="customer_phone" placeholder="+7 (___) ___-__-__" :required="true" />
                            <x-form-field label="Email" name="customer_email" type="email" placeholder="mail@yandex.ru" />
                            <x-form-field class="checkout-form__full" label="Город" name="customer_city" placeholder="Москва" :required="true" />
                            <x-form-field class="checkout-form__full" label="Комментарий к заказу" name="customer_comment" placeholder="Текст...." :textarea="true" />
                        </div>
                    </section>

                    <section class="checkout-card">
                        <header class="checkout-card__head"><h2 class="checkout-card__title">Выбор способа получения</h2><span class="checkout-card__step">Шаг 2</span></header>
                        <div class="checkout-shipping">
                            @foreach ($deliveryMethods as $method)
                                @php($presentation = $deliveryPresentation[$method->code->value] ?? null)
                                <x-delivery-method
                                    :value="$method->code->value"
                                    :image="$presentation['image'] ?? ''"
                                    :image-width="$presentation['width'] ?? ''"
                                    :image-height="$presentation['height'] ?? ''"
                                    :title="$method->title"
                                    :desc="$method->description"
                                    :price="$method->base_price"
                                    :price-mode="$method->price_mode->value"
                                    :checked="old('delivery_method') === $method->code->value"
                                />
                            @endforeach
                        </div>
                        @error('delivery_method')<span class="field__error">{{ $message }}</span>@enderror
                    </section>

                    <section class="checkout-card">
                        <header class="checkout-card__head"><h2 class="checkout-card__title">Оплата</h2><span class="checkout-card__step">Шаг 3</span></header>
                        <div class="checkout-payments">
                            @foreach ($paymentMethods as $method)
                                <x-payment-method :value="$method->code->value" :icon="$paymentIcons[$method->code->value]" :title="$method->title" :desc="$method->description" :checked="old('payment_method') === $method->code->value" />
                            @endforeach
                        </div>
                        @error('payment_method')<span class="field__error">{{ $message }}</span>@enderror
                    </section>
                </div>

                <aside class="checkout-order">
                    <h2 class="checkout-order__title">Ваш заказ</h2>
                    <ul class="checkout-order__list">
                        @foreach ($items as $item)
                            <li class="checkout-order__item">
                                <span class="checkout-order__thumb"><img src="{{ $item->image_snapshot }}" alt="" aria-hidden="true"></span>
                                <div class="checkout-order__info"><p class="checkout-order__name">{{ $item->title_snapshot }}</p>@if ($item->optionSummary())<p class="checkout-order__opts">{{ $item->optionSummary() }}</p>@endif<p class="checkout-order__qty">{{ $item->quantity }} шт. × {{ number_format((float) $item->price_snapshot, 0, ',', ' ') }} ₽</p></div>
                                <span class="checkout-order__sum">{{ number_format($item->lineTotal(), 0, ',', ' ') }} ₽</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="checkout-order__row"><span>{{ $totals['items_count'] }} товар(ов) на сумму</span><span class="checkout-order__value">{{ number_format($totals['subtotal'], 0, ',', ' ') }} ₽</span></div>
                    <div class="checkout-order__row" data-cart-discount-row @if ($totals['discount_total'] <= 0) hidden @endif><span>Скидка</span><span class="checkout-order__value" data-cart-discount>−{{ number_format($totals['discount_total'], 0, ',', ' ') }} ₽</span></div>
                    <div class="checkout-order__row"><span>Доставка</span><span class="checkout-order__value" data-checkout-delivery>—</span></div>
                    <div class="checkout-order__total">
                        <span data-checkout-total-label>Сумма товаров</span>
                        <span class="checkout-order__total-value" data-checkout-total data-checkout-subtotal="{{ number_format((float) $totals['total'], 2, '.', '') }}">{{ number_format($totals['total'], 0, ',', ' ') }} ₽</span>
                    </div>
                    <button type="submit" class="btn checkout-order__submit">Заказать</button>
                    <label class="checkout-order__agree">
                        <input type="checkbox" name="agree_terms" value="1" @checked(old('agree_terms')) required><span class="checkout-order__agree-box"></span>
                        <span class="checkout-order__agree-text">Я согласен на обработку персональных данных и принимаю <a href="{{ route('legal.privacy-policy') }}">политику конфиденциальности</a>.</span>
                    </label>
                </aside>
            </form>
        @endif
    </div>
@endsection
