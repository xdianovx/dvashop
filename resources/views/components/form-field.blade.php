@props([
    'label' => '',
    'name' => '',
    'type' => 'text',
    'placeholder' => '',
    'required' => false,
    'textarea' => false,
])

<label class="field {{ $attributes->get('class') }}">
    <span class="field__label">
        {{ $label }}@if ($required)<span class="field__req">*</span>@endif
    </span>
    @if ($textarea)
        <textarea class="field__input field__input--area" name="{{ $name }}" placeholder="{{ $placeholder }}"></textarea>
    @else
        <input class="field__input" type="{{ $type }}" name="{{ $name }}" placeholder="{{ $placeholder }}">
    @endif
</label>
