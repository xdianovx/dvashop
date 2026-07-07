@extends('layouts.app')

@section('title', 'Поколения модели Alfa Romeo 159 — 2POROGA')

@php
    $generations = [
        ['title' => '1 поколение / 2005-2011', 'bodies' => ['Седан 4 дв.', 'Универсал 5 дв.']],
    ];

    $otherModels = [
        ['name' => 'Alfa Romeo 159', 'sub' => 'Седан 4 дв.'],
        ['name' => 'Alfa Romeo 159', 'sub' => 'Универсал 5 дв.'],
    ];
@endphp

@section('content')
    <section class="model-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => 'Каталог', 'url' => route('catalog.index')],
                ['label' => 'Alfa Romeo', 'url' => route('catalog.make')],
                ['label' => '159'],
            ]" />

            <h1 class="model-page__title">Поколения модели <span>Alfa Romeo 159</span></h1>

            @foreach ($generations as $generation)
                <h2 class="model-page__gen"><span class="model-page__gen-body">{{ $generation['bodies'][0] }} /
                    </span>{{ $generation['title'] }}</h2>
                <ul class="model-grid model-page__grid">
                    @foreach ($generation['bodies'] as $body)
                        <li>
                            <x-model-card :href="route('catalog.generation')" :name="$body" variant="body" />
                        </li>
                    @endforeach
                </ul>
            @endforeach

            <h2 class="model-page__title model-page__title--other">Другие модели <span>Alfa Romeo</span></h2>
            <ul class="model-grid model-page__grid">
                @foreach ($otherModels as $model)
                    <li>
                        <x-model-card :href="route('catalog.model')" :name="$model['name']" :sub="$model['sub']" variant="other" />
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endsection
