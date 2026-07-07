@extends('layouts.app')

@section('title', 'Вопросы и ответы — 2POROGA')

@php
    $tabs = ['Частые вопросы', 'Продукция', 'Оплата и доставка', 'Замена и возврат', 'Работа с сайтом', 'Партнёры'];

    $questions = [
        [
            'q' => 'Товар НЕнадлежащего качества. Как поменять?',
            'a' => 'Напишите нам в течение 14 дней после получения. Мы согласуем замену или возврат и отправим инструкцию по отправке товара.',
        ],
        [
            'q' => 'Сколько времени ждать замены товара?',
            'a' => 'После согласования замены новая деталь отправляется в течение 3 рабочих дней. Срок доставки зависит от вашего региона и выбранной транспортной компании.',
        ],
        [
            'q' => 'Как происходит возврат?',
            'a' => 'Свяжитесь с нами удобным способом, опишите причину возврата и приложите фото. Мы подтвердим возврат и вышлем инструкцию по отправке товара обратно.',
        ],
        [
            'q' => 'Как вернуть деньги?',
            'a' => 'После получения и проверки товара мы оформляем возврат средств. Деньги возвращаются тем же способом, которым была произведена оплата.',
        ],
        [
            'q' => 'Куда вернуться деньги?',
            'a' => 'Средства возвращаются на карту или счёт, с которого была произведена оплата заказа. Срок зачисления зависит от вашего банка — обычно от 3 до 10 рабочих дней.',
        ],
        [
            'q' => 'Товар имеет заводской брак, повреждение или не соответствует заявленному. Как вернуть?',
            'a' => 'Сфотографируйте деталь и упаковку, напишите нам в течение 14 дней после получения. Мы проверим брак, согласуем возврат или замену и возьмём расходы на пересылку на себя.',
        ],
    ];
@endphp

@section('content')
    <section class="faq-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'Вопросы и ответы'],
            ]" />

            <h1 class="faq-page__title">Вопросы и ответы</h1>
            <p class="faq-page__subtitle">Здесь вы найдете ответы на частые вопросы по нашему сервису</p>

            <div class="faq-page__tabs" data-faq-tabs>
                @foreach ($tabs as $tab)
                    <button type="button" class="faq-page__tab {{ $loop->first ? 'faq-page__tab--active' : '' }}"
                        data-faq-tab>{{ $tab }}</button>
                @endforeach
            </div>

            <ul class="faq__list faq-page__list">
                @foreach ($questions as $item)
                    <li class="faq__item" data-faq-item>
                        <button type="button" class="faq__head" data-faq-toggle aria-expanded="false">
                            <span class="faq__q">{{ $item['q'] }}</span>
                            <span class="faq__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </span>
                        </button>
                        <div class="faq__body">
                            <p class="faq__a">{{ $item['a'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>

            <a href="#" class="btn faq__cta faq-page__cta">Бесплатная консультация</a>
            <a href="{{ route('home') }}" class="btn faq__cta faq-page__cta-home">Вернуться на главную</a>
        </div>
    </section>
@endsection
