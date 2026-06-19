<section class="search">
    <div class="container">
        <x-section-heading title="Быстрый поиск запчастей">
            <x-slot:icon>
                <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="18" r="14" />
                    <path d="M38 38 L28 28" />
                </svg>
            </x-slot:icon>
        </x-section-heading>

        <form class="search__form" action="#" method="get">
            <button type="button" class="search__field">
                <span class="search__field-label">Марка</span>
                <span class="search__field-value">Выберите марку автомобиля</span>
            </button>

            <span class="search__divider" aria-hidden="true"></span>

            <button type="button" class="search__field search__field--model">
                <span class="search__field-label">Модель</span>
                <span class="search__field-value">Выберите модель автомобиля</span>
            </button>

            <button type="submit" class="btn btn--primary search__submit">
                <span class="search__submit-text">Показать</span>
                <span class="search__submit-icon" aria-hidden="true">
                    <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="18" cy="18" r="14" />
                        <path d="M38 38 L28 28" />
                    </svg>
                </span>
            </button>
        </form>
    </div>
</section>
