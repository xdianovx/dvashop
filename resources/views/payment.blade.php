@extends('layouts.app')

@section('content')
    <section class="payment-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => $pageData->title],
            ]" />

            <h1 class="payment-page__title">{{ $pageData->title }}</h1>

            @if ($pageData->methods !== [])
                <ul class="payment-page__grid">
                    @foreach ($pageData->methods as $method)
                        <li class="payment-page__card">
                            <div class="payment-page__card-head">
                                <img src="{{ $method['icon'] }}" alt="" class="payment-page__icon" aria-hidden="true">
                                @if ($method['title_lines'] !== [])
                                    <h2 class="payment-page__card-title">
                                        @foreach ($method['title_lines'] as $line)
                                            {{ $line }}@if (! $loop->last)<br>@endif
                                        @endforeach
                                    </h2>
                                @endif
                            </div>
                            @if ($method['description'])
                                <p class="payment-page__card-text">{{ $method['description'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ route('home') }}" class="btn payment-page__cta">Вернуться на главную</a>
        </div>
    </section>
@endsection
