@props([
    'title' => null,
    'metrics' => [],
])

@if ($metrics !== [])
    <section class="about">
        <div class="container">
            <div class="about__box">
                @if ($title)
                    <h2 class="about__title">{{ $title }}</h2>
                @endif

                <div class="about__grid">
                    @foreach ($metrics as $metric)
                        <div class="about__card">
                            <div class="about__icon">
                                <img src="{{ $metric['icon'] }}" alt="" aria-hidden="true">
                            </div>
                            <div class="about__body">
                                <p class="about__num">
                                    @if ($metric['prefix'])
                                        <span class="about__num-sm">{{ $metric['prefix'] }}</span>
                                    @endif
                                    <span class="about__num-lg">{{ $metric['value'] }}</span>
                                    @if ($metric['suffix'])
                                        <span class="about__num-sm">{{ $metric['suffix'] }}</span>
                                    @endif
                                </p>
                                <p class="about__text">{{ $metric['text'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
