@props([
    'item' => null,
    'name' => '',
    'options' => '',
    'qty' => 1,
    'price' => '',
    'unit' => '',
    'image' => '/img/products/threshold.png',
])

<article class="cart-item">
    <div class="cart-item__image">
        <img src="{{ $image }}" alt="{{ $name }}" loading="lazy">
    </div>

    <div class="cart-item__info">
        <a href="#" class="cart-item__name">{{ $name }}</a>
        <p class="cart-item__opts">{{ $options }}</p>

        @if ($item)
            <form method="POST" action="{{ route('cart.items.destroy', $item) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="cart-item__remove">Удалить</button>
            </form>
        @else
            <button type="button" class="cart-item__remove">Удалить</button>
        @endif
    </div>

    <div class="cart-item__qty" data-qty>
        <button type="button" class="cart-item__qty-btn" data-qty-minus aria-label="Убавить">−</button>
        <span class="cart-item__qty-value" data-qty-value>{{ $qty }}</span>
        <button type="button" class="cart-item__qty-btn" data-qty-plus aria-label="Добавить">+</button>
    </div>

    <div class="cart-item__price">
        <span class="cart-item__sum">{{ $price }}</span>
        <span class="cart-item__unit">{{ $unit }}</span>
    </div>
</article>
