@props(['title'])

<div class="section-heading">
    @isset($icon)
        <span class="section-heading__icon" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <h2 class="section-heading__title">{{ $title }}</h2>
</div>
