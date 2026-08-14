@extends('layouts.app')

@section('content')
    <section class="faq-page">
        <div class="container">
            <x-breadcrumbs :items="[
                ['label' => 'Главная', 'url' => route('home')],
                ['label' => $pageData->title],
            ]" />

            <h1 class="faq-page__title">{{ $pageData->title }}</h1>
            @if ($pageData->subtitle)
                <p class="faq-page__subtitle">{{ $pageData->subtitle }}</p>
            @endif

            @if ($pageData->categories !== [])
                <div class="faq-page__tabs" data-faq-tabs>
                    @foreach ($pageData->categories as $category)
                        <button type="button" class="faq-page__tab {{ $loop->first ? 'faq-page__tab--active' : '' }}"
                            data-faq-tab="{{ $loop->index }}">{{ $category['title'] }}</button>
                    @endforeach
                </div>

                @foreach ($pageData->categories as $category)
                    <ul class="faq__list faq-page__list {{ $loop->first ? '' : 'faq-page__list--hidden' }}"
                        data-faq-panel="{{ $loop->index }}">
                        @foreach ($category['items'] as $item)
                            <li class="faq__item" data-faq-item>
                                <button type="button" class="faq__head" data-faq-toggle aria-expanded="false">
                                    <span class="faq__q">{{ $item['question'] }}</span>
                                    <span class="faq__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m6 9 6 6 6-6" />
                                        </svg>
                                    </span>
                                </button>
                                <div class="faq__body">
                                    <p class="faq__a">{{ $item['answer'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif

            <a href="#storefront-inquiry" class="btn faq__cta faq-page__cta" data-inquiry-open>Бесплатная консультация</a>
            @if (($storefront ?? null)?->phoneUrl && $storefront?->phoneDisplay)
                <a href="{{ $storefront->phoneUrl }}" class="faq-page__phone">Позвонить: {{ $storefront->phoneDisplay }}</a>
            @endif
            <a href="{{ route('home') }}" class="btn faq__cta faq-page__cta-home">Вернуться на главную</a>
        </div>
    </section>

    <x-storefront-inquiry-modal
        :type="\App\Enums\StorefrontInquiryType::GeneralConsultation->value"
        source-code="faq"
        title="Бесплатная консультация"
    />
@endsection
