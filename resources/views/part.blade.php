@extends('layouts.app')

@php
    $selectedOptionValueIds = $variant->optionValues->pluck('id')->map(fn ($id) => (int) $id);
    $selectedCanBePurchased = $variant->stock_status !== \App\Enums\StockStatus::OutOfStock
        && ! ($variant->stock_status === \App\Enums\StockStatus::InStock && $variant->stock_quantity !== null && $variant->stock_quantity <= 0);
    $selectedMaxQuantity = $variant->stock_status === \App\Enums\StockStatus::InStock && $variant->stock_quantity !== null
        ? max(1, $variant->stock_quantity)
        : 999;
    $selectedStockModifier = match ($variant->stock_status) {
        \App\Enums\StockStatus::InStock => 'in-stock',
        \App\Enums\StockStatus::OutOfStock => 'out-of-stock',
        \App\Enums\StockStatus::PreOrder => 'pre-order',
    };
    $descriptionLines = preg_split('/\R/u', (string) $description) ?: [];
@endphp

@section('content')
    <div class="container">
        <x-breadcrumbs :items="$breadcrumbs" />
        <div class="part-top">
            <div class="part-gallery">
                <div class="part-gallery__main-wrap">
                    <div class="swiper part-gallery__main" data-gallery-main>
                        <div class="swiper-wrapper">
                            @foreach ($gallery as $image)
                                <div class="swiper-slide part-gallery__slide"><img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy"></div>
                            @endforeach
                        </div>
                        <div class="part-gallery__pagination"></div>
                    </div>
                </div>
                @if ($gallery->count() > 1)
                    <div class="swiper part-gallery__thumbs" data-gallery-thumbs><div class="swiper-wrapper">
                        @foreach ($gallery as $image)
                            <div class="swiper-slide part-gallery__thumb"><img src="{{ $image['url'] }}" alt="" aria-hidden="true"></div>
                        @endforeach
                    </div></div>
                @endif
            </div>

            <div class="part-buy">
                <h1 class="part-buy__title">{{ $product->title }}</h1>
                <p class="part-buy__article">Артикул: <span data-selected-sku>{{ $variant->sku ?: $product->sku }}</span></p>
                @if ($product->category)<p class="part-buy__article">Категория: {{ $product->category->title }}</p>@endif
                @if ($product->partType)<p class="part-buy__article">Тип детали: {{ $product->partType->full_title ?: $product->partType->title }}</p>@endif
                <p
                    class="part-buy__stock part-buy__stock--{{ $selectedStockModifier }}"
                    data-selected-stock
                    data-in-stock-label="{{ \App\Enums\StockStatus::InStock->label() }}"
                    data-out-of-stock-label="{{ \App\Enums\StockStatus::OutOfStock->label() }}"
                    data-pre-order-label="{{ \App\Enums\StockStatus::PreOrder->label() }}"
                    data-unavailable-label="Такой комбинации нет"
                >
                    <span class="part-buy__stock-icon" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="10" cy="10" r="8.5" />
                            <path d="m6.5 10 2.5 2.5 4.5-5" />
                        </svg>
                    </span>
                    <span data-selected-stock-label>{{ $variant->stock_status->label() }}</span>
                </p>
                <p class="part-buy__price" data-selected-price>{{ \App\ViewModels\ProductCardViewModel::formatPrice($variant->price ?? $product->price) }} руб.</p>

                <form action="{{ route('cart.items.store') }}" method="post" @if ($optionGroups->isNotEmpty() || $variants->count() > 1) data-product-options @endif>
                    @csrf
                    @if ($optionGroups->isNotEmpty())
                        <input type="hidden" name="product_variant_id" value="{{ $variant->getKey() }}" data-selected-variant required>
                        @foreach ($optionGroups as $optionGroup)
                            <div class="part-option-group" data-option-group="{{ $optionGroup['id'] }}">
                                <span class="part-option-group__label">{{ $optionGroup['title'] }}:</span>
                                @if ($optionGroup['code'] === 'profile')
                                    <div class="part-tabs">
                                        @foreach ($optionGroup['values'] as $optionValue)
                                            <button
                                                type="button"
                                                class="part-tab @if ($selectedOptionValueIds->contains($optionValue['id'])) part-tab--active @endif"
                                                data-product-option
                                                data-option-group="{{ $optionGroup['id'] }}"
                                                data-option-value="{{ $optionValue['id'] }}"
                                                aria-pressed="{{ $selectedOptionValueIds->contains($optionValue['id']) ? 'true' : 'false' }}"
                                            >{{ $optionValue['title'] }}</button>
                                        @endforeach
                                    </div>
                                @elseif ($optionGroup['input_type'] === 'select')
                                    <select class="part-option-select" data-product-option data-option-group="{{ $optionGroup['id'] }}">
                                        @foreach ($optionGroup['values'] as $optionValue)
                                            <option value="{{ $optionValue['id'] }}" @selected($selectedOptionValueIds->contains($optionValue['id']))>{{ $optionValue['title'] }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <div class="part-radios">
                                        @foreach ($optionGroup['values'] as $optionValue)
                                            <label class="part-radio">
                                                <input
                                                    type="radio"
                                                    name="option_group_{{ $optionGroup['id'] }}"
                                                    value="{{ $optionValue['id'] }}"
                                                    data-product-option
                                                    data-option-group="{{ $optionGroup['id'] }}"
                                                    data-option-value="{{ $optionValue['id'] }}"
                                                    @checked($selectedOptionValueIds->contains($optionValue['id']))
                                                >
                                                <span class="part-radio__dot" aria-hidden="true"></span>
                                                <span class="part-radio__label">{{ $optionValue['title'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @elseif ($variants->count() > 1)
                        <div class="part-option-group">
                            <label class="part-option-group__label" for="product-variant">Вариант:</label>
                            <select id="product-variant" name="product_variant_id" data-product-variant-fallback required>
                                @foreach ($variants as $availableVariant)
                                    <option value="{{ $availableVariant->getKey() }}" @selected($availableVariant->is($variant))>
                                        {{ $availableVariant->title ?: $availableVariant->optionSummary() ?: $availableVariant->sku }} — {{ \App\ViewModels\ProductCardViewModel::formatPrice($availableVariant->price ?? $product->price) }} ₽
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="product_variant_id" value="{{ $variant->getKey() }}">
                    @endif
                    @if ($optionGroups->isNotEmpty() || $variants->count() > 1)
                        <script type="application/json" data-variant-matrix>@json($variantMatrix)</script>
                    @endif
                    <label class="part-option-group__label" for="product-quantity">Количество:</label>
                    <input id="product-quantity" type="number" name="quantity" value="1" min="1" max="{{ $selectedMaxQuantity }}" data-product-quantity required>
                    <div class="part-buy__actions">
                        <button type="submit" class="btn part-buy__cart" data-add-to-cart @disabled(! $selectedCanBePurchased)>Добавить в корзину</button>
                    </div>
                </form>

                @if ($deliveryMethods->isNotEmpty())
                    <ul class="part-delivery">
                        @foreach ($deliveryMethods as $method)
                            <li class="part-delivery__row">
                                <span class="part-delivery__info">
                                    <span class="part-delivery__icon" aria-hidden="true"><img src="/img/part/deliver.svg" alt=""></span>
                                    {{ $method->title }} — {{ (float) $method->base_price > 0 ? number_format((float) $method->base_price, 0, ',', ' ').' ₽' : 'Бесплатно' }}
                                </span>
                                <a href="{{ route('payment') }}" class="part-delivery__more">Подробнее ›</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        @if (filled($description) || $product->characteristics->isNotEmpty())
            <section class="part-info">
                @if (filled($description))
                    <div class="part-info__col">
                        <h2 class="part-info__heading">Описание</h2>
                        <div class="part-desc">@foreach ($descriptionLines as $line){{ $line }}@unless ($loop->last)<br>@endunless @endforeach</div>
                    </div>
                @endif
                @if ($product->characteristics->isNotEmpty())
                    <div class="part-info__col">
                        <h2 class="part-info__heading">Характеристики</h2>
                        <dl class="part-specs">
                            @foreach ($product->characteristics as $characteristic)
                                <div class="part-specs__row"><dt class="part-specs__key">{{ $characteristic->name }}</dt><dd class="part-specs__val">{{ $characteristic->value }}@if ($characteristic->unit) {{ $characteristic->unit }}@endif</dd></div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </section>
        @endif

        @if ($product->fitments->isNotEmpty())
            <section class="part-info">
                <div class="part-info__col">
                    <h2 class="part-info__heading">Применимость</h2>
                    <ul>
                        @foreach ($product->fitments as $fitment)
                            <li>{{ $fitment->generation?->display_title }}@if ($fitment->note) — {{ $fitment->note }}@endif</li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        @if ($related->isNotEmpty())
            <section class="part-related">
                <h2 class="part-related__title">С этим товаром покупают</h2>
                <ul class="products">@foreach ($related as $relatedProduct)<li class="products__item"><x-product-card :product="$relatedProduct" /></li>@endforeach</ul>
            </section>
        @endif
    </div>
@endsection
