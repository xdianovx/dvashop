@props(['title' => null, 'makes' => []])

<section class="search">
    <div class="container">
        <x-section-heading :title="$title">
            <x-slot:icon>
                <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="18" r="14" />
                    <path d="M38 38 L28 28" />
                </svg>
            </x-slot:icon>
        </x-section-heading>

        <form
            class="search__form"
            action="{{ route('catalog.index') }}"
            method="get"
            data-vehicle-search
            data-models-url-template="{{ route('storefront.vehicle-makes.models', ['makeSlug' => '__MAKE__']) }}"
        >
            <label class="search__field">
                <span class="search__field-label">Марка</span>
                <select class="search__field-value" name="make" required data-vehicle-make>
                    <option value="">Выберите марку автомобиля</option>
                    @foreach ($makes as $make)
                        <option value="{{ $make['slug'] }}">{{ $make['title'] }}</option>
                    @endforeach
                </select>
            </label>

            <span class="search__divider" aria-hidden="true"></span>

            <label class="search__field search__field--model">
                <span class="search__field-label">Модель</span>
                <select class="search__field-value" name="model" disabled data-vehicle-model>
                    <option value="">Выберите модель автомобиля</option>
                </select>
            </label>

            <span class="search__status" data-vehicle-search-status aria-live="polite" aria-atomic="true" hidden>
                <span class="search__status-spinner" data-vehicle-search-spinner aria-hidden="true"></span>
                <span data-vehicle-search-status-text></span>
            </span>

            <button type="submit" class="btn btn--primary search__submit">
                <span class="search__submit-text">Показать</span>
                <span class="search__submit-icon" aria-hidden="true">
                    <svg viewBox="0 0 42 42" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="18" cy="18" r="14" />
                        <path d="M38 38 L28 28" />
                    </svg>
                </span>
            </button>
        </form>
    </div>
</section>
