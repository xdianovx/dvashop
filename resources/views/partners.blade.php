@extends('layouts.app')

@section('title', 'Партнёрам — 2POROGA')

@php
    $benefits = ['Специальные цены на детали', 'Персональный менеджер', 'Работает по всей РФ', 'Приоритет в отправке'];

    $partnerTypes = [
        ['icon' => '/img/partners/coop-opt.svg', 'label' => 'Оптовые<br>и роздничные сети', 'mod' => 'opt'],
        ['icon' => '/img/partners/coop-sto.svg', 'label' => 'СТО и частные<br>кузовные сервисы', 'mod' => 'sto'],
        ['icon' => '/img/partners/coop-online.svg', 'label' => 'Онлайн продавец<br>запчастей', 'mod' => 'online'],
        ['icon' => '/img/partners/coop-dropship.svg', 'label' => 'Дропшиппинг', 'mod' => 'dropship'],
    ];
@endphp

@section('content')
    <section class="partners-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'Партнёрам'],
            ]" />

            <h1 class="partners-page__title">Преимущества работы<br>с AVTOPOROGI.RU</h1>
            <p class="partners-page__subtitle">Для постоянных клиентов действуют <strong>специальные условия</strong>
                на покупку и доставку кузовных запчастей</p>

            <div class="partners-page__benefits">
                @foreach ($benefits as $benefit)
                    <div class="partners-page__benefit">{{ $benefit }}</div>
                @endforeach
                <a href="tel:+79062444151" class="partners-page__benefit partners-page__benefit--phone">
                    <img src="/img/partners/phone-icon.svg" alt="" aria-hidden="true">
                    <span>Номер телефона для связи с нами <strong>+7 (906) 244-41-51</strong></span>
                </a>
            </div>

            <h2 class="partners-page__title partners-page__title--coop">Приглашаем<br>к сотрудничеству</h2>

            <ul class="partners-page__coop">
                @foreach ($partnerTypes as $type)
                    <li class="partners-page__coop-card partners-page__coop-card--{{ $type['mod'] }}">
                        <img src="{{ $type['icon'] }}" alt="" class="partners-page__coop-icon" aria-hidden="true">
                        <p class="partners-page__coop-label">{!! $type['label'] !!}</p>
                    </li>
                @endforeach
            </ul>

            <a href="tel:+79062444151" class="btn partners-page__mob-cta">Сотрудничать</a>

            <div class="partners-page__about">
                <div class="partners-page__photo">
                    <img src="/img/partners/team.jpg" alt="Команда Автопороги.ру">
                </div>
                <div class="partners-page__about-body">
                    <h2 class="partners-page__about-title">Автопороги.ру - это</h2>
                    <ul class="partners-page__list">
                        <li class="partners-page__list-item"><strong>Собственное производство.</strong> Детали в наличии
                            или изготовим за <strong>1 день</strong> с момента обращения</li>
                        <li class="partners-page__list-item">База замеров деталей на более <strong>3000</strong>
                            автомобилей</li>
                        <li class="partners-page__list-item"><strong>Оплата при получении.</strong> Проверяете, потом
                            оплачиваете</li>
                        <li class="partners-page__list-item">Используем металл ХКС и цинк <strong>от 0,8 до 1.5
                                мм</strong></li>
                        <li class="partners-page__list-item">Удобный <strong>обмен</strong> и лёгкий
                            <strong>возврат</strong> по заказам</li>
                    </ul>
                </div>
            </div>

            <a href="tel:+79062444151" class="btn partners-page__mob-cta">Написать нам</a>
        </div>
    </section>
@endsection
