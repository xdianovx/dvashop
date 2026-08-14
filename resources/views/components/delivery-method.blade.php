@props([
    'value' => '',
    'image' => '',
    'imageWidth' => '',
    'imageHeight' => '',
    'title' => '',
    'desc' => '',
    'checked' => false,
    'price' => null,
    'priceMode' => 'fixed',
])

<label class="ship">
    <input type="radio" name="delivery_method" value="{{ $value }}" data-delivery-price="{{ number_format((float) ($price ?? 0), 2, '.', '') }}" data-delivery-price-mode="{{ $priceMode }}" @checked($checked) required>
    <span class="ship__box">
        <span class="ship__logo">
            @if ($image)<img src="{{ $image }}" alt="" aria-hidden="true" width="{{ $imageWidth }}" height="{{ $imageHeight }}">@endif
        </span>
        <span class="ship__text">
            <span class="ship__title">{{ $title }}</span>
            <span class="ship__desc">{{ $desc }}</span>
            @if ($price !== null)
                <span class="ship__desc">{{ \App\Enums\DeliveryPriceMode::from($priceMode)->storefrontPriceText($price) }}</span>
            @endif
        </span>
    </span>
</label>
