@props([
    'value' => '',
    'icon' => '',
    'title' => '',
    'desc' => '',
    'checked' => false,
])

<label class="pay">
    <input type="radio" name="payment" value="{{ $value }}" @checked($checked)>
    <span class="pay__box">
        <span class="pay__icon">{{ $icon }}</span>
        <span class="pay__text">
            <span class="pay__title">{{ $title }}</span>
            <span class="pay__desc">{{ $desc }}</span>
        </span>
    </span>
</label>
