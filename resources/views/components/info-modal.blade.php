@props(['id', 'title'])

<div id="{{ $id }}" class="info-modal" data-info-modal>
    <button type="button" class="info-modal__backdrop" data-info-close tabindex="-1" aria-label="Закрыть"></button>
    <section class="info-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" tabindex="-1">
        <button type="button" class="info-modal__close" data-info-close aria-label="Закрыть">×</button>
        <h2 id="{{ $id }}-title" class="info-modal__title">{{ $title }}</h2>
        <div class="info-modal__text">{{ $slot }}</div>
    </section>
</div>
