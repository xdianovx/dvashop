@props([
    'title' => 'Часто задаваемые вопросы',
    'items' => [],
])

@php
    $items = count($items)
        ? $items
        : array_fill(0, 5, [
            'q' => 'У вас есть пороги на все модели авто?',
            'a' => 'Да, мы производим кузовные пороги и арки практически на все модели автомобилей. Если нужной позиции нет в каталоге — оставьте заявку, и мы изготовим деталь под ваш автомобиль.',
        ]);
@endphp

<section class="faq">
    <div class="container">
        <h2 class="faq__title">{{ $title }}</h2>
        <p class="faq__subtitle">
            Если не нашли ответ на нужный вопрос <a href="#" class="faq__link">оставьте заявку</a> или свяжитесь с
            нами. Мы с удовольствием расскажем все подробнее и проконсультируем вас.
        </p>

        <ul class="faq__list">
            @foreach ($items as $item)
                <li class="faq__item" data-faq-item>
                    <button type="button" class="faq__head" data-faq-toggle aria-expanded="false">
                        <span class="faq__q">{{ $item['q'] }}</span>
                        <span class="faq__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </span>
                    </button>
                    <div class="faq__body">
                        <p class="faq__a">{{ $item['a'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>

        <a href="#" class="btn faq__cta">Бесплатная консультация</a>
    </div>
</section>
