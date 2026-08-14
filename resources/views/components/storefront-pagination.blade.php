@props(['paginator'])

@if ($paginator->hasPages())
    @php
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        $windowStart = max(1, $currentPage - 2);
        $windowEnd = min($lastPage, $currentPage + 2);
    @endphp

    <nav class="storefront-pagination" aria-label="Навигация по страницам">
        @if ($paginator->onFirstPage())
            <span class="storefront-pagination__control storefront-pagination__control--disabled" aria-disabled="true">Назад</span>
        @else
            <a class="storefront-pagination__control" href="{{ $paginator->previousPageUrl() }}" rel="prev">Назад</a>
        @endif

        <span class="storefront-pagination__mobile">Страница {{ $currentPage }} из {{ $lastPage }}</span>

        <span class="storefront-pagination__pages">
            @if ($windowStart > 1)
                <a class="storefront-pagination__page" href="{{ $paginator->url(1) }}">1</a>
                @if ($windowStart > 2)
                    <span class="storefront-pagination__ellipsis" aria-hidden="true">…</span>
                @endif
            @endif

            @foreach (range($windowStart, $windowEnd) as $page)
                @if ($page === $currentPage)
                    <span class="storefront-pagination__page storefront-pagination__page--current" aria-current="page">{{ $page }}</span>
                @else
                    <a class="storefront-pagination__page" href="{{ $paginator->url($page) }}">{{ $page }}</a>
                @endif
            @endforeach

            @if ($windowEnd < $lastPage)
                @if ($windowEnd < $lastPage - 1)
                    <span class="storefront-pagination__ellipsis" aria-hidden="true">…</span>
                @endif
                <a class="storefront-pagination__page" href="{{ $paginator->url($lastPage) }}">{{ $lastPage }}</a>
            @endif
        </span>

        @if ($paginator->hasMorePages())
            <a class="storefront-pagination__control" href="{{ $paginator->nextPageUrl() }}" rel="next">Вперёд</a>
        @else
            <span class="storefront-pagination__control storefront-pagination__control--disabled" aria-disabled="true">Вперёд</span>
        @endif
    </nav>
@endif
