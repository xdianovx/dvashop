@extends('layouts.app')

@section('content')
    <section class="how-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => $pageData->title],
            ]" />

            <h1 class="how-page__title">{{ $pageData->title }}</h1>

            @if ($pageData->steps !== [])
                <ol class="how-page__grid">
                    @foreach ($pageData->steps as $step)
                        <li class="how-page__step">
                            <span class="how-page__num" aria-hidden="true">{{ $step['number'] }}</span>
                            <img src="{{ $step['icon'] }}" alt="" class="how-page__icon" aria-hidden="true">
                            <h2 class="how-page__step-title">
                                @foreach ($step['title_lines'] as $line)
                                    {{ $line }}@if (! $loop->last)<br>@endif
                                @endforeach
                            </h2>
                            <p class="how-page__step-text">
                                @foreach ($step['segments'] as $segment)
                                    @if ($segment['strong'])<strong>{{ $segment['text'] }}</strong>@else{{ $segment['text'] }}@endif@if ($segment['break_after'])<br>@endif
                                @endforeach
                                @if ($step['show_phone'] && ($storefront ?? null)?->phoneUrl && $storefront?->phoneDisplay)
                                    <br><a href="{{ $storefront->phoneUrl }}" class="how-page__phone">{{ $storefront->phoneDisplay }}</a>
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ol>
            @endif

            <a href="{{ route('home') }}" class="btn how-page__cta">Вернуться на главную</a>
        </div>
    </section>
@endsection
