@props(['items' => []])

@if ($items !== [])
    <section class="hero-circles-section">
        <div class="container">
            <div class="hero-circles">
                <div class="hero-circles__wrap">
                    @foreach ($items as $item)
                        <a href="{{ $item['url'] }}" class="hero-circles__item"
                            @if ($item['open_in_new_tab']) target="_blank" rel="noopener noreferrer" @endif>
                            <div class="hero-circles__item_img">
                                <img src="{{ $item['image'] }}" alt="">
                            </div>
                            <div class="hero-circles__item_title">{{ $item['title'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
