@extends('layouts.app')

@section('content')
    <section class="legal-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => $pageData->title],
            ]" />

            <article class="legal-page__article">
                <h1 class="legal-page__title">{{ $pageData->title }}</h1>

                <div class="legal-page__body">
                    @foreach ($pageData->paragraphs as $paragraph)
                        <p>
                            @foreach ($paragraph as $line)
                                {{ $line }}@if (! $loop->last)<br>@endif
                            @endforeach
                        </p>
                    @endforeach
                </div>

                @if ($pageData->requisites !== [])
                    <section class="legal-page__requisites" aria-labelledby="legal-requisites-title">
                        <h2 id="legal-requisites-title" class="legal-page__subtitle">Реквизиты</h2>
                        <dl class="legal-page__requisites-list">
                            @foreach ($pageData->requisites as $requisite)
                                <div class="legal-page__requisite">
                                    <dt>{{ $requisite['label'] }}</dt>
                                    <dd>{{ $requisite['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif
            </article>
        </div>
    </section>
@endsection
