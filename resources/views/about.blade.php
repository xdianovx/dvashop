@extends('layouts.app')

@section('title', 'О нас — 2POROGA')

@section('content')
    <section class="about-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'О нас'],
            ]" />

            <div class="about-hero">
                <img src="/img/about-page/hero.png" alt="" class="about-hero__bg">
                <div class="about-hero__overlay" aria-hidden="true"></div>
                <div class="about-hero__inner">
                    <span class="about-hero__badge">О компании</span>
                    <h1 class="about-hero__title">Наша экспертиза —<br>ваше преимущество!</h1>
                    <p class="about-hero__text"><strong>С 2014 года</strong> мы специализируемся на производстве
                        высококачественных автомобильных кузовных деталей: ремонтных порогов, арок, ремкомплектов
                        дверей, багажника и пола</p>
                    <a href="tel:88001005625" class="btn about-hero__cta">Связаться</a>
                </div>
            </div>

            <ul class="about-metrics">
                <li class="about-metrics__card">
                    <span class="about-metrics__icon">
                        <img src="/img/about-page/metric-1.svg" alt="" aria-hidden="true">
                    </span>
                    <div class="about-metrics__body">
                        <h2 class="about-metrics__num">150 000+ деталей</h2>
                        <p class="about-metrics__text">За годы работы мы изготовили более 150 000 деталей</p>
                    </div>
                </li>
                <li class="about-metrics__card">
                    <span class="about-metrics__icon">
                        <img src="/img/about-page/metric-2.svg" alt="" aria-hidden="true">
                    </span>
                    <div class="about-metrics__body">
                        <h2 class="about-metrics__num">3000 моделей автомобилей</h2>
                        <p class="about-metrics__text">Создали одну из самых полных в России баз геометрии кузовных
                            элементов.</p>
                    </div>
                </li>
            </ul>

            <div class="about-tech">
                <div class="about-tech__head">
                    <h2 class="about-tech__title">Технологии точности</h2>
                    <p class="about-tech__lead">Для безупречного соответствия оригиналу мы используем комплексный
                        подход:</p>
                </div>
                <ul class="about-tech__list">
                    <li class="about-tech__item">
                        <span class="about-tech__num">01</span>
                        <p class="about-tech__text">Качественная высокоуглеродистая сталь толщиной
                            <strong>0,8 - 1,5 мм,</strong> обеспечивающая прочность и долговечность.</p>
                    </li>
                    <li class="about-tech__item">
                        <span class="about-tech__num">02</span>
                        <p class="about-tech__text"><strong>3D-сканирование</strong> для точного повторения сложных
                            геометрий.</p>
                    </li>
                    <li class="about-tech__item">
                        <span class="about-tech__num">03</span>
                        <p class="about-tech__text"><strong>Современное ЧПУ-оборудование</strong> для идеального
                            раскроя и гибки.</p>
                    </li>
                </ul>
            </div>

            <div class="about-goal">
                <img src="/img/about-page/goal-car.svg" alt="" class="about-goal__car" aria-hidden="true">
                <p class="about-goal__text"><strong>Наша цель</strong><span class="about-goal__dash"> — </span>предлагать
                    надежные и точные решения, которые экономят ваше время и деньги, сохраняя высокое качество
                    ремонта.</p>
            </div>

            <a href="{{ route('home') }}" class="btn about-page__cta">Вернуться на главную</a>
        </div>
    </section>
@endsection
