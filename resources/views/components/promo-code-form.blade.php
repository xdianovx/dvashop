@props(['totals', 'class' => '', 'compact' => false])

<section {{ $attributes->class(['promo-code', 'promo-code--compact' => $compact, $class]) }} data-promo-panel aria-labelledby="promo-code-title">
    <h3 class="promo-code__title @if ($compact) visually-hidden @endif" id="promo-code-title">Промокод</h3>

    <form action="{{ route('cart.promo-code.store') }}" method="post" data-promo-apply @if ($totals['promo_applied']) hidden @endif>
        @csrf
        <label class="promo-code__label @if ($compact) visually-hidden @endif" for="promo-code-input">Введите промокод</label>
        <div class="promo-code__controls">
            <input
                class="promo-code__input"
                id="promo-code-input"
                name="promo_code"
                value="{{ old('promo_code') }}"
                placeholder="Введите промокод..."
                minlength="3"
                maxlength="64"
                pattern="[A-Za-z0-9_\-]{3,64}"
                autocomplete="off"
                autocapitalize="characters"
                aria-describedby="promo-code-feedback"
                required
            >
            <button class="btn promo-code__button" type="submit" aria-label="Применить промокод">
                <span class="promo-code__button-text">Применить</span>
                <svg class="promo-code__button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 5 7 7-7 7" />
                </svg>
            </button>
        </div>
    </form>

    <div class="promo-code__applied" data-promo-applied @unless ($totals['promo_applied']) hidden @endunless>
        <p class="promo-code__applied-title">Промокод применён!</p>
        <p class="promo-code__applied-code"><span class="promo-code__badge" data-promo-code>{{ $totals['promo_code'] }}</span> <span data-promo-name>{{ $totals['promo_name'] }}</span></p>
        <form action="{{ route('cart.promo-code.destroy') }}" method="post" data-promo-remove>
            @csrf
            @method('DELETE')
            <button class="promo-code__remove" type="submit">Удалить промокод</button>
        </form>
    </div>

    <p
        class="promo-code__feedback @error('promo_code') promo-code__feedback--error @enderror"
        id="promo-code-feedback"
        data-promo-feedback
        role="status"
        aria-live="polite"
    >@error('promo_code'){{ $message }}@else{{ session('promo_status') ?: $totals['promo_message'] }}@enderror</p>
</section>
