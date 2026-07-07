@extends('layouts.app')

@section('title', 'Оплата и доставка — 2POROGA')

@php
    $methods = [
        [
            'icon' => '/img/payment/cash.svg',
            'title' => 'Наличный расчет<br>или оплата картой',
            'text' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в вашем городе или при доставке товара по указанному вами адресу',
        ],
        [
            'icon' => '/img/payment/invoice.svg',
            'title' => 'Безналичный расчёт<br>для юридических лиц',
            'text' => 'Осуществляется юридическими лицами путём перечисления денежных средств на расчётный счёт нашей компании на основании выставленного счёта',
        ],
        [
            'icon' => '/img/payment/delivery.svg',
            'title' => 'Доставка транспортной компанией',
            'text' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в Вашем городе или при доставке товара по указанному вами адресу',
        ],
    ];
@endphp

@section('content')
    <section class="payment-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'Оплата и доставка'],
            ]" />

            <h1 class="payment-page__title">Оплата и доставка</h1>

            <ul class="payment-page__grid">
                @foreach ($methods as $method)
                    <li class="payment-page__card">
                        <div class="payment-page__card-head">
                            <img src="{{ $method['icon'] }}" alt="" class="payment-page__icon" aria-hidden="true">
                            <h2 class="payment-page__card-title">{!! $method['title'] !!}</h2>
                        </div>
                        <p class="payment-page__card-text">{{ $method['text'] }}</p>
                    </li>
                @endforeach
            </ul>

            <a href="{{ route('home') }}" class="btn payment-page__cta">Вернуться на главную</a>
        </div>
    </section>
@endsection
