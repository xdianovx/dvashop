@extends('layouts.app')

@section('content')
    <section class="model-page">
        <div class="container">
            <x-breadcrumbs :items="$breadcrumbs" />
            <h1 class="model-page__title">{{ $seoH1 ?? 'Поколения модели '.$make->title.' '.$model->title }}</h1>
            @foreach ($generationGroups as $generationGroup)
                <h2 class="model-page__gen">
                    @if ($generationGroup['generations']->pluck('body')->filter()->isNotEmpty())
                        <span class="model-page__gen-body">{{ $generationGroup['generations']->pluck('body')->filter()->unique()->implode(' / ') }} / </span>
                    @endif
                    {{ $generationGroup['title'] }}@if ($generationGroup['years_label']) / {{ $generationGroup['years_label'] }}@endif
                </h2>
                <ul class="model-grid model-page__grid">
                    @foreach ($generationGroup['generations'] as $generation)
                        <li>
                            <x-model-card
                                :href="route('catalog.generation', [$make->slug, $model->slug, $generation->slug])"
                                :name="$generation->body ?: $generation->title"
                                :sub="$generation->years_label"
                                :img="$generation->image_url"
                                variant="body"
                            />
                        </li>
                    @endforeach
                </ul>
            @endforeach

            @if ($otherModels->isNotEmpty())
                <h2 class="model-page__title model-page__title--other">Другие модели <span>{{ $make->title }}</span></h2>
                <ul class="model-grid model-page__grid model-page__grid--other">
                    @foreach ($otherModels as $otherModel)
                        <li>
                            <x-model-card
                                :href="route('catalog.model', [$make->slug, $otherModel->slug])"
                                :name="$otherModel->title"
                                :img="$otherModelImages->get($otherModel->getKey())"
                                variant="other"
                            />
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-storefront-seo-text :text="$seoText ?? null" />
        </div>
    </section>
@endsection
