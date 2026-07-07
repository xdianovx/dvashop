@extends('layouts.app')

@section('title', 'Как мы работаем — 2POROGA')

@php
    $steps = [
        [
            'icon' => '/img/how/step-1.svg',
            'title' => 'Выбираете товар<br>и оставляете заявку',
            'text' => 'Оставьте заявку, самостоятельно подобрав товар в каталоге и оформив заказ через корзину, либо позвоните по бесплатному номеру:<br><a href="tel:+79395554925" class="how-page__phone">+7(939)5554925</a>',
        ],
        [
            'icon' => '/img/how/step-2.svg',
            'title' => 'Перезваниваем<br>и уточняем детали',
            'text' => 'Компетентные менеджеры с опытом работы более 3 лет перезвонят, уточнят детали и ответят на все интересующие вас вопросы, чтобы сэкономить ваше время и деньги',
        ],
        [
            'icon' => '/img/how/step-3.svg',
            'title' => 'Оформляем и готовим<br>заказ к отправке',
            'text' => 'Каждому заказу присваивается внутренний номер, после чего он упаковывается нашими сотрудниками на складе в Санкт-Петербурге. Детали уточняйте при оформлении',
        ],
        [
            'icon' => '/img/how/step-4.svg',
            'title' => 'Передаем груз<br>в службу доставки',
            'text' => 'Avtoporogi сотрудничает с крупнейшей ТК России — <strong>СДЭК.</strong><br>Это позволяет предложить нам лучшие условия доставки, даже если вы живете в небольшом городке',
        ],
        [
            'icon' => '/img/how/step-5.svg',
            'title' => 'Курьер доставляет<br>Ваш заказ',
            'text' => 'Вы можете получить свой заказ в ближайшем пункте выдачи ТК или прямо из рук курьера по месту жительства',
        ],
        [
            'icon' => '/img/how/step-6.svg',
            'title' => 'Оплачиваете покупку<br>при получении',
            'text' => 'Оплата заказа возможна наличными, картой и по счету (для юрлиц).',
        ],
    ];
@endphp

@section('content')
    <section class="how-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'Как мы работаем'],
            ]" />

            <h1 class="how-page__title">Как мы работаем</h1>

            <ol class="how-page__grid">
                @foreach ($steps as $step)
                    <li class="how-page__step">
                        <span class="how-page__num" aria-hidden="true">{{ $loop->iteration }}</span>
                        <img src="{{ $step['icon'] }}" alt="" class="how-page__icon" aria-hidden="true">
                        <h2 class="how-page__step-title">{!! $step['title'] !!}</h2>
                        <p class="how-page__step-text">{!! $step['text'] !!}</p>
                    </li>
                @endforeach
            </ol>

            <a href="{{ route('home') }}" class="btn how-page__cta">Вернуться на главную</a>
        </div>
    </section>
@endsection
