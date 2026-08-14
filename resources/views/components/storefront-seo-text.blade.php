@props(['text' => null])

@if (filled($text))
    <section class="storefront-seo-text" aria-label="Дополнительная информация">
        <p class="storefront-seo-text__content">{{ $text }}</p>
    </section>
@endif
