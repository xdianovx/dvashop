@extends('layouts.app')

@section('content')
    <section class="brand-page">
        <div class="container brand-page__body">
            <div class="brand-page__crumbs"><x-breadcrumbs :items="$breadcrumbs" /></div>
            <h1 class="brand-page__title">{{ $seoH1 ?? 'Модели автомобилей '.$make->title }}</h1>
            <h2 class="brand-page__subtitle">Выберите модель</h2>
            <form class="brand-page__search" action="{{ route('catalog.index') }}" method="get">
                <input type="search" class="brand-page__search-input" placeholder="Поиск по каталогу" name="q">
                <button type="submit" class="btn btn--primary brand-page__search-submit">Показать</button>
                <button type="submit" class="brand-page__search-icon" aria-label="Найти">
                    <img src="/img/brand-page/search.svg" alt="" aria-hidden="true">
                </button>
            </form>
            <ul class="model-grid brand-page__grid">
                @foreach ($models as $vehicleModel)
                    <li><x-model-card :href="route('catalog.model', [$make->slug, $vehicleModel->slug])" :name="$vehicleModel->title" :img="$modelImages->get($vehicleModel->getKey())" variant="model" /></li>
                @endforeach
            </ul>
            <x-storefront-seo-text :text="$seoText ?? null" />
        </div>
    </section>
@endsection
