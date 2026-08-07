@props(['cards' => []])

@if ($cards !== [])
    <section class="categories">
        <div class="container">
            <div class="categories__grid">
                @foreach ($cards as $card)
                    <a href="{{ $card['url'] }}" class="categories__card categories__card--{{ $card['modifier'] }}">
                        @foreach ($card['layers'] as $layer)
                            <span class="{{ $layer['class'] }}" aria-hidden="true">
                                <img src="{{ $layer['src'] }}" alt="">
                            </span>
                        @endforeach
                        <span class="categories__pill {{ count($card['title_lines']) > 1 ? 'categories__pill--two-lines' : '' }}">
                            <span class="categories__pill-text">
                                @foreach ($card['title_lines'] as $line)
                                    {{ $line }}@if (! $loop->last)<br>@endif
                                @endforeach
                            </span>
                            <span class="categories__pill-icon" aria-hidden="true">
                                <img src="/img/categories/arrow.svg" alt="">
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif
