@props([
    'label' => '',
    'name' => '',
    'type' => 'text',
    'placeholder' => '',
    'required' => false,
    'textarea' => false,
    'value' => null,
])

<label class="field {{ $attributes->get('class') }}">
    <span class="field__label">
        {{ $label }}@if ($required) <span class="field__req">*</span>@endif
    </span>
    @if ($textarea)
        <textarea class="field__input field__input--area" name="{{ $name }}" placeholder="{{ $placeholder }}" @required($required)>{{ old($name, $value) }}</textarea>
    @else
        <input class="field__input" type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}" placeholder="{{ $placeholder }}" @required($required)>
    @endif
    @error($name)<span class="field__error">{{ $message }}</span>@enderror
</label>
