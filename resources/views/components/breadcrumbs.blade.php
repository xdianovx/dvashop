@props(['items' => []])

<nav class="breadcrumbs" aria-label="Навигация по сайту">
    <ol class="breadcrumbs__list">
        @foreach ($items as $item)
            <li class="breadcrumbs__item">
                @if (!empty($item['url']) && !$loop->last)
                    <a href="{{ $item['url'] }}" class="breadcrumbs__link">{{ $item['label'] }}</a>
                @else
                    <span class="breadcrumbs__current" aria-current="page">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
