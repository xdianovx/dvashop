@props([
    'productId',
    'isFavorite' => false,
    'buttonClass',
])

@php
    $addUrl = route('favorites.items.store');
    $removeUrl = route('favorites.items.destroy', ['product' => $productId]);
    $label = $isFavorite ? 'Удалить из избранного' : 'Добавить в избранное';
@endphp

<form
    action="{{ $isFavorite ? $removeUrl : $addUrl }}"
    method="post"
    data-favorite-form
    data-favorite-product-id="{{ $productId }}"
    data-favorite-add-url="{{ $addUrl }}"
    data-favorite-remove-url="{{ $removeUrl }}"
    data-favorites-url="{{ route('favorites.show') }}"
>
    @csrf
    @if ($isFavorite)
        @method('delete')
    @endif
    <input type="hidden" name="product_id" value="{{ $productId }}">
    <button
        type="submit"
        class="{{ $buttonClass }} @if ($isFavorite) favorite-toggle--active @endif"
        aria-pressed="{{ $isFavorite ? 'true' : 'false' }}"
        aria-label="{{ $label }}"
        data-favorite-toggle
    >
        <img src="/img/product/heart.svg" alt="" aria-hidden="true" width="32" height="32">
    </button>
</form>
