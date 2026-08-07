@extends('layouts.app')

@section('content')
    <section class="partners-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => implode(' ', $pageData->titleLines)],
            ]" />

            <h1 class="partners-page__title">
                @foreach ($pageData->titleLines as $line)
                    {{ $line }}@if (! $loop->last)<br>@endif
                @endforeach
            </h1>
            @if ($pageData->subtitleSegments !== [])
                <p class="partners-page__subtitle">
                    @foreach ($pageData->subtitleSegments as $segment)
                        @if ($segment['strong'])<strong>{{ $segment['text'] }}</strong>@else{{ $segment['text'] }}@endif
                    @endforeach
                </p>
            @endif

            @if ($pageData->benefits !== [] || (($storefront ?? null)?->phoneUrl && $storefront?->phoneDisplay))
                <div class="partners-page__benefits">
                    @foreach ($pageData->benefits as $benefit)
                        <div class="partners-page__benefit">{{ $benefit }}</div>
                    @endforeach
                    @if (($storefront ?? null)?->phoneUrl && $storefront?->phoneDisplay)
                        <a href="{{ $storefront->phoneUrl }}" class="partners-page__benefit partners-page__benefit--phone">
                            <img src="/img/partners/phone-icon.svg" alt="" aria-hidden="true">
                            <span>Номер телефона для связи с нами <strong>{{ $storefront->phoneDisplay }}</strong></span>
                        </a>
                    @endif
                </div>
            @endif

            @if ($pageData->partnerTypes !== [])
                @if ($pageData->cooperationTitleLines !== [])
                    <h2 class="partners-page__title partners-page__title--coop">
                        @foreach ($pageData->cooperationTitleLines as $line)
                            {{ $line }}@if (! $loop->last)<br>@endif
                        @endforeach
                    </h2>
                @endif

                <ul class="partners-page__coop">
                    @foreach ($pageData->partnerTypes as $type)
                        <li class="partners-page__coop-card partners-page__coop-card--{{ $type['modifier'] }}">
                            <img src="{{ $type['icon'] }}" alt="" class="partners-page__coop-icon" aria-hidden="true">
                            <p class="partners-page__coop-label">
                                @foreach ($type['title_lines'] as $line)
                                    {{ $line }}@if (! $loop->last)<br>@endif
                                @endforeach
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (($storefront ?? null)?->phoneUrl)
                <a href="{{ $storefront->phoneUrl }}" class="btn partners-page__mob-cta">Сотрудничать</a>
            @endif

            @if ($pageData->facts !== [])
                <div class="partners-page__about">
                    <div class="partners-page__photo">
                        <img src="/img/partners/team.jpg" alt="Команда Автопороги.ру">
                    </div>
                    <div class="partners-page__about-body">
                        @if ($pageData->aboutTitle)
                            <h2 class="partners-page__about-title">{{ $pageData->aboutTitle }}</h2>
                        @endif
                        <ul class="partners-page__list">
                            @foreach ($pageData->facts as $fact)
                                <li class="partners-page__list-item">
                                    @foreach ($fact['segments'] as $segment)
                                        @if ($segment['strong'])<strong>{{ $segment['text'] }}</strong>@else{{ $segment['text'] }}@endif
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @if (($storefront ?? null)?->phoneUrl)
                <a href="{{ $storefront->phoneUrl }}" class="btn partners-page__mob-cta">Написать нам</a>
            @endif
        </div>
    </section>
@endsection
