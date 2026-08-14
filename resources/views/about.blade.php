@extends('layouts.app')

@section('content')
    <section class="about-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => $pageData->title],
            ]" />

            @if ($pageData->hero)
                <div class="about-hero">
                    <img src="/img/about-page/hero.png" alt="" class="about-hero__bg">
                    <div class="about-hero__overlay" aria-hidden="true"></div>
                    <div class="about-hero__inner">
                        @if ($pageData->hero['badge'])
                            <span class="about-hero__badge">{{ $pageData->hero['badge'] }}</span>
                        @endif
                        @if ($pageData->hero['title_lines'] !== [])
                            <h1 class="about-hero__title">
                                @foreach ($pageData->hero['title_lines'] as $line)
                                    {{ $line }}@if (! $loop->last)<br>@endif
                                @endforeach
                            </h1>
                        @endif
                        @if ($pageData->hero['lead_prefix'] || $pageData->hero['lead_text'])
                            <p class="about-hero__text">
                                @if ($pageData->hero['lead_prefix'])
                                    <strong>{{ $pageData->hero['lead_prefix'] }}</strong>
                                @endif
                                {{ $pageData->hero['lead_text'] }}
                            </p>
                        @endif
                        <a href="#storefront-inquiry" class="btn about-hero__cta" data-inquiry-open>Связаться</a>
                        @if (($storefront ?? null)?->phoneUrl && $storefront?->phoneDisplay)
                            <a href="{{ $storefront->phoneUrl }}" class="about-hero__phone">Позвонить: {{ $storefront->phoneDisplay }}</a>
                        @endif
                    </div>
                </div>
            @endif

            @if ($pageData->metrics !== [])
                <ul class="about-metrics">
                    @foreach ($pageData->metrics as $metric)
                        <li class="about-metrics__card">
                            <span class="about-metrics__icon">
                                <img src="{{ $metric['icon'] }}" alt="" aria-hidden="true">
                            </span>
                            <div class="about-metrics__body">
                                <h2 class="about-metrics__num">{{ $metric['title'] }}</h2>
                                @if ($metric['text'])
                                    <p class="about-metrics__text">{{ $metric['text'] }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($pageData->technologies && $pageData->technologies['items'] !== [])
                <div class="about-tech">
                    <div class="about-tech__head">
                        @if ($pageData->technologies['title'])
                            <h2 class="about-tech__title">{{ $pageData->technologies['title'] }}</h2>
                        @endif
                        @if ($pageData->technologies['subtitle'])
                            <p class="about-tech__lead">{{ $pageData->technologies['subtitle'] }}</p>
                        @endif
                    </div>
                    <ul class="about-tech__list">
                        @foreach ($pageData->technologies['items'] as $item)
                            <li class="about-tech__item">
                                <span class="about-tech__num">{{ $item['number'] }}</span>
                                <p class="about-tech__text">
                                    @foreach ($item['segments'] as $segment)
                                        @if ($segment['strong'])<strong>{{ $segment['text'] }}</strong>@else{{ $segment['text'] }}@endif
                                    @endforeach
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($pageData->goal && ($pageData->goal['label'] || $pageData->goal['body']))
                <div class="about-goal">
                    <img src="/img/about-page/goal-car.svg" alt="" class="about-goal__car" aria-hidden="true">
                    <p class="about-goal__text">
                        @if ($pageData->goal['label'])<strong>{{ $pageData->goal['label'] }}</strong>@endif
                        @if ($pageData->goal['label'] && $pageData->goal['body'])<span class="about-goal__dash"> — </span>@endif
                        {{ $pageData->goal['body'] }}
                    </p>
                </div>
            @endif

            <a href="{{ route('home') }}" class="btn about-page__cta">Вернуться на главную</a>
        </div>
    </section>

    <x-storefront-inquiry-modal
        :type="\App\Enums\StorefrontInquiryType::GeneralConsultation->value"
        source-code="about"
        title="Связаться с нами"
    />
@endsection
