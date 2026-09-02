@props(['totals', 'class' => ''])

<section {{ $attributes->class(['promo-code', $class]) }} data-promo-panel aria-labelledby="promo-code-title">
    <h3 class="promo-code__title" id="promo-code-title">Промокод</h3>

    <form action="{{ route('cart.promo-code.store') }}" method="post" data-promo-apply @if ($totals['promo_applied']) hidden @endif>
        @csrf
        <label class="promo-code__label" for="promo-code-input">Введите промокод</label>
        <div class="promo-code__controls">
            <input
                class="promo-code__input"
                id="promo-code-input"
                name="promo_code"
                value="{{ old('promo_code') }}"
                minlength="3"
                maxlength="64"
                pattern="[A-Za-z0-9_-]{3,64}"
                autocomplete="off"
                autocapitalize="characters"
                aria-describedby="promo-code-feedback"
                required
            >
            <button class="btn promo-code__button" type="submit">Применить</button>
        </div>
    </form>

    <div class="promo-code__applied" data-promo-applied @unless ($totals['promo_applied']) hidden @endunless>
        <p><span class="promo-code__badge" data-promo-code>{{ $totals['promo_code'] }}</span> <span data-promo-name>{{ $totals['promo_name'] }}</span></p>
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
