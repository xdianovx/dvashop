@props([
    'href' => '#',
    'name',
    'sub' => null,
    'img' => null,
    'variant' => 'model',
])

<a href="{{ $href }}" class="model-card model-card--{{ $variant }}">
    <span class="model-card__thumb">
        @if ($img)
            <img src="{{ $img }}" alt="{{ $name }}" loading="lazy">
        @endif
    </span>
    <span class="model-card__name">{{ $name }}</span>
    @if ($sub)
        <span class="model-card__sub">{{ $sub }}</span>
    @endif
</a>
