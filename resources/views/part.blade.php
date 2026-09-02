@extends('layouts.app')

@php
    $selectedOptionValueIds = $variant->optionValues->pluck('id')->map(fn ($id) => (int) $id);
    $variantPresentationById = collect($variantMatrix)->keyBy('variant_id');
    $selectedMaxQuantity = $variant->stock_status === \App\Enums\StockStatus::InStock && $variant->stock_quantity !== null
        ? max(1, $variant->stock_quantity)
        : 999;
    $selectedStockModifier = match ($variant->stock_status) {
        \App\Enums\StockStatus::InStock => 'in-stock',
        \App\Enums\StockStatus::OutOfStock => 'out-of-stock',
        \App\Enums\StockStatus::PreOrder => 'pre-order',
    };
    $descriptionLines = preg_split('/\R/u', (string) $description) ?: [];
    $displaySku = $variant->sku ?: $product->sku;
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
                    <x-favorite-toggle
                        :product-id="$product->getKey()"
                        :is-favorite="in_array((int) $product->getKey(), $favoriteProductIds ?? [], true)"
                        button-class="part-gallery__fav"
                    />
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
                <h1 class="part-buy__title">{{ $seoH1 ?? $product->title }}</h1>
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
                <p class="part-buy__price" data-selected-price>{{ $selectedPriceLabel }}</p>

                <form action="{{ route('cart.items.store') }}" method="post" data-cart-add @if ($optionGroups->isNotEmpty() || $variants->count() > 1) data-product-options @endif>
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
                                        {{ $availableVariant->title ?: $availableVariant->optionSummary() ?: $availableVariant->sku }} — {{ $variantPresentationById->get($availableVariant->getKey())['price_label'] }}
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
                    <div class="part-qty" data-product-qty>
                        <button type="button" class="part-qty__btn" data-product-qty-step="-1" aria-label="Убавить количество">−</button>
                        <input id="product-quantity" class="part-qty__value" type="number" name="quantity" value="1" min="1" max="{{ $selectedMaxQuantity }}" data-product-quantity required>
                        <button type="button" class="part-qty__btn" data-product-qty-step="1" aria-label="Добавить количество">+</button>
                    </div>
                    <div class="part-buy__actions">
                        <button type="submit" class="btn part-buy__cart" data-add-to-cart @disabled(! $selectedCanBePurchased)>
                            <span data-cart-button-label>Добавить в корзину</span>
                        </button>
                        <a href="#storefront-inquiry" class="btn part-buy__consult" data-inquiry-open>Получить консультацию</a>
                    </div>
                </form>

                <ul class="part-delivery">
                    <li class="part-delivery__row">
                        <span class="part-delivery__info">
                            <span class="part-delivery__icon" aria-hidden="true"><img src="/img/part/cost.svg" alt=""></span>
                            Стоимость доставки: от 490 руб.
                        </span>
                        <button type="button" class="part-delivery__more" data-info-open="part-delivery-info">Подробнее ›</button>
                    </li>
                    <li class="part-delivery__row">
                        <span class="part-delivery__info">
                            <span class="part-delivery__icon" aria-hidden="true"><img src="/img/part/deliver.svg" alt=""></span>
                            Расчётное время доставки: 1–3 дня
                        </span>
                        <button type="button" class="part-delivery__more" data-info-open="part-delivery-time-info">Подробнее ›</button>
                    </li>
                    <li class="part-delivery__row">
                        <span class="part-delivery__info">
                            <span class="part-delivery__icon" aria-hidden="true"><img src="/img/part/vozvrat.svg" alt=""></span>
                            Возврат товара: в течение 2 недель
                        </span>
                        <button type="button" class="part-delivery__more" data-info-open="part-return-info">Подробнее ›</button>
                    </li>
                </ul>

                <div class="part-accordion">
                    <details class="part-accordion__item" open>
                        <summary class="part-accordion__head">
                            <span class="part-accordion__title">Доставка</span>
                            <span class="part-accordion__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </span>
                        </summary>
                        <div class="part-accordion__body"><x-delivery-info code="delivery" /></div>
                    </details>
                    <details class="part-accordion__item">
                        <summary class="part-accordion__head">
                            <span class="part-accordion__title">Время доставки</span>
                            <span class="part-accordion__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </span>
                        </summary>
                        <div class="part-accordion__body"><x-delivery-info code="time" /></div>
                    </details>
                    <details class="part-accordion__item">
                        <summary class="part-accordion__head">
                            <span class="part-accordion__title">Возврат</span>
                            <span class="part-accordion__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </span>
                        </summary>
                        <div class="part-accordion__body"><x-delivery-info code="return" /></div>
                    </details>
                </div>

                <x-info-modal id="part-delivery-info" title="Доставка">
                    <x-delivery-info code="delivery" />
                </x-info-modal>

                <x-info-modal id="part-delivery-time-info" title="Расчётное время доставки">
                    <x-delivery-info code="time" />
                </x-info-modal>

                <x-info-modal id="part-return-info" title="Сроки возврата и обмена">
                    <x-delivery-info code="return" />
                </x-info-modal>
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

        @if ($related->isNotEmpty())
            <section class="part-related">
                <h2 class="part-related__title">С этим товаром покупают</h2>
                <ul class="related-grid">@foreach ($related as $relatedProduct)<li class="related-grid__item"><x-related-card :product="$relatedProduct" /></li>@endforeach</ul>
            </section>
        @endif
        <x-storefront-seo-text :text="$seoText ?? null" />
    </div>

    <x-storefront-inquiry-modal
        :type="\App\Enums\StorefrontInquiryType::ProductConsultation->value"
        source-code="product"
        :product-id="$product->getKey()"
        :product-variant-id="$variant->getKey()"
        title="Консультация по товару"
    />
@endsection
