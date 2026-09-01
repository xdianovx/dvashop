@props(['items' => []])

@if ($items !== [])
    <section class="hero-circles-section">
        <div class="container">
            <div class="hero-circles">
                <div class="hero-circles__wrap">
                    @foreach ($items as $groupIndex => $item)
                        <button
                            type="button"
                            class="hero-circles__item"
                            data-story-open="{{ $groupIndex }}"
                            aria-label="Открыть сторис: {{ $item['title'] }}"
                        >
                            <span class="hero-circles__item_img">
                                <img src="{{ $item['cover_url'] }}" alt="" loading="lazy">
                            </span>
                            <span class="hero-circles__item_title">{{ $item['title'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <div class="story-modal" data-story-modal aria-hidden="true">
        <button class="story-modal__backdrop" type="button" data-story-close tabindex="-1" aria-label="Закрыть сторис"></button>
        <div class="story-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="story-modal-title" tabindex="-1">
            <h2 class="visually-hidden" id="story-modal-title">Сторис</h2>
            <div class="story-modal__progress" data-story-progress aria-label="Прогресс сторис"></div>
            <button class="story-modal__pause" type="button" data-story-pause aria-label="Приостановить сторис">
                <svg data-story-pause-icon aria-hidden="true" viewBox="0 0 24 24">
                    <path d="M7 5.5v13M17 5.5v13" />
                </svg>
                <svg data-story-play-icon aria-hidden="true" viewBox="0 0 24 24" hidden>
                    <path d="m8 5 11 7-11 7Z" />
                </svg>
            </button>
            <button class="story-modal__close" type="button" data-story-close aria-label="Закрыть сторис">
                <svg aria-hidden="true" viewBox="0 0 24 24">
                    <path d="m6 6 12 12M18 6 6 18" />
                </svg>
            </button>

            <div class="story-modal__swiper swiper" data-story-swiper>
                <div class="swiper-wrapper">
                    @foreach ($items as $groupIndex => $group)
                        @foreach ($group['items'] as $itemIndex => $story)
                            <article
                                class="story-modal__slide swiper-slide"
                                data-story-slide
                                data-group-index="{{ $groupIndex }}"
                                data-item-index="{{ $itemIndex }}"
                                data-group-size="{{ count($group['items']) }}"
                                data-media-type="{{ $story['type'] }}"
                                @if ($story['duration_ms']) data-duration-ms="{{ $story['duration_ms'] }}" @endif
                                aria-label="{{ $group['title'] }}, сторис {{ $itemIndex + 1 }} из {{ count($group['items']) }}"
                            >
                                <p class="story-modal__group-title">{{ $group['title'] }}</p>
                                @if ($story['type'] === 'video')
                                    <video class="story-modal__media" src="{{ $story['media_url'] }}" playsinline muted preload="metadata" controls @if ($story['alt'] !== '') aria-label="{{ $story['alt'] }}" @else aria-hidden="true" @endif></video>
                                @else
                                    <img class="story-modal__media" src="{{ $story['media_url'] }}" alt="{{ $story['alt'] }}">
                                @endif
                                <div class="story-modal__fallback" data-story-media-fallback hidden>
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path d="M4 5.5h16v13H4zM7 15l3.5-4 2.5 3 2-2 2 2.5M8 9h.01" />
                                    </svg>
                                    <span>Медиа недоступно</span>
                                </div>

                                @if ($story['cta_url'])
                                    <a class="story-modal__cta btn btn--primary" href="{{ $story['cta_url'] }}"
                                        @if ($story['open_in_new_tab']) target="_blank" rel="noopener noreferrer" @endif>
                                        {{ $story['cta_label'] }}
                                    </a>
                                @endif
                            </article>
                        @endforeach
                    @endforeach
                </div>
            </div>

            <button class="story-modal__nav story-modal__nav--prev" type="button" data-story-prev aria-label="Предыдущая сторис">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m15 5-7 7 7 7" /></svg>
            </button>
            <button class="story-modal__nav story-modal__nav--next" type="button" data-story-next aria-label="Следующая сторис">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7" /></svg>
            </button>
            <p class="visually-hidden" data-story-status aria-live="polite"></p>
        </div>
    </div>
@endif
