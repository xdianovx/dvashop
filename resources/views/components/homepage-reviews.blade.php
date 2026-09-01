@props(['title' => 'Отзывы клиентов'])

<section class="homepage-reviews section" aria-labelledby="homepage-reviews-title">
    <div class="container">
        <x-section-heading id="homepage-reviews-title" :title="$title ?: 'Отзывы клиентов'" />
        <review-lab data-widgetid="69984c4658896b169079008c"></review-lab>
    </div>
</section>

@once
    @push('scripts')
        <script src="https://app.reviewlab.ru/widget/index-es2015.js" defer></script>
    @endpush
@endonce
