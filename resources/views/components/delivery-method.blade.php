@props([
    'value' => '',
    'image' => '',
    'imageWidth' => '',
    'imageHeight' => '',
    'title' => '',
    'desc' => '',
    'checked' => false,
])

<label class="ship">
    <input type="radio" name="delivery" value="{{ $value }}" @checked($checked)>
    <span class="ship__box">
        <span class="ship__logo">
            <img src="{{ $image }}" alt="" aria-hidden="true" width="{{ $imageWidth }}" height="{{ $imageHeight }}">
        </span>
        <span class="ship__text">
            <span class="ship__title">{{ $title }}</span>
            <span class="ship__desc">{{ $desc }}</span>
        </span>
    </span>
</label>
