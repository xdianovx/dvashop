import Swiper from 'swiper';
import { Thumbs, Pagination } from 'swiper/modules';
import 'swiper/css';
import 'swiper/css/thumbs';
import 'swiper/css/pagination';

const storefrontLoader = document.querySelector('[data-storefront-loader]');
const storefrontLoaderLabel = storefrontLoader?.querySelector('[data-storefront-loader-label]');
let activeRequestCount = 0;

function beginRequest(label = 'Загрузка…') {
    activeRequestCount += 1;

    if (!storefrontLoader || !storefrontLoaderLabel) return;

    storefrontLoader.hidden = false;
    storefrontLoaderLabel.textContent = '';
    window.requestAnimationFrame(() => {
        storefrontLoaderLabel.textContent = label;
    });
}

function endRequest() {
    activeRequestCount = Math.max(0, activeRequestCount - 1);

    if (activeRequestCount === 0 && storefrontLoader) {
        storefrontLoader.hidden = true;
    }
}

function resetRequestUi() {
    activeRequestCount = 0;
    if (storefrontLoader) storefrontLoader.hidden = true;

    document.querySelectorAll('[data-request-pending]').forEach((element) => {
        element.removeAttribute('data-request-pending');
        element.removeAttribute('aria-busy');
    });
    document.querySelectorAll('button[data-loading="true"]').forEach((button) => {
        button.removeAttribute('data-loading');
        button.removeAttribute('aria-disabled');
    });
}

const initStorefrontFeature = (name, initializer) => {
    try {
        initializer();
    } catch (error) {
        console.error(`[storefront:${name}]`, error);
    }
};

// Vehicle models are loaded only for the selected active make. Without JavaScript,
// the disabled model field leaves the existing make-only catalog redirect intact.
document.querySelectorAll('[data-vehicle-search]').forEach((form) => {
    const make = form.querySelector('[data-vehicle-make]');
    const model = form.querySelector('[data-vehicle-model]');
    const status = form.querySelector('[data-vehicle-search-status]');
    const statusText = form.querySelector('[data-vehicle-search-status-text]');
    const statusSpinner = form.querySelector('[data-vehicle-search-spinner]');
    const urlTemplate = form.dataset.modelsUrlTemplate;
    let request = null;

    if (!make || !model || !status || !statusText || !statusSpinner || !urlTemplate) return;

    const setStatus = (message = '', loading = false) => {
        status.hidden = message === '';
        statusText.textContent = message;
        statusSpinner.hidden = !loading;
    };

    const resetModel = () => {
        model.replaceChildren(new Option('Выберите модель автомобиля', ''));
        model.disabled = true;
    };

    make.addEventListener('change', async () => {
        request?.abort();
        resetModel();
        setStatus();

        if (!make.value) return;

        const controller = new AbortController();
        request = controller;
        const selectedMake = make.value;
        setStatus('Загружаем модели…', true);
        beginRequest('Загружаем модели…');

        try {
            const response = await fetch(
                urlTemplate.replace('__MAKE__', encodeURIComponent(selectedMake)),
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            );

            if (!response.ok) throw new Error(`Vehicle models request failed: ${response.status}`);

            const models = await response.json();
            if (!Array.isArray(models)) throw new Error('Vehicle models response is invalid.');
            if (make.value !== selectedMake) return;

            models.forEach((item) => {
                if (typeof item?.title === 'string' && typeof item?.slug === 'string') {
                    model.append(new Option(item.title, item.slug));
                }
            });

            model.disabled = false;
            setStatus(models.length > 0 ? 'Модели загружены.' : 'У этой марки нет доступных моделей.');
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus('Не удалось загрузить модели. Можно перейти в каталог выбранной марки.');
        } finally {
            if (request === controller) request = null;
            endRequest();
        }
    });
});

// Product gallery: main slider synced with thumbnails when thumbnails exist.
function initProductGallery() {
    const mainGallery = document.querySelector('[data-gallery-main]');
    const thumbsGallery = document.querySelector('[data-gallery-thumbs]');

    if (!mainGallery || !thumbsGallery) return;

    const thumbs = new Swiper(thumbsGallery, {
        slidesPerView: 4,
        spaceBetween: 10,
        watchSlidesProgress: true,
    });

    new Swiper(mainGallery, {
        modules: [Thumbs, Pagination],
        thumbs: { swiper: thumbs },
        pagination: { el: mainGallery.querySelector('.part-gallery__pagination'), clickable: true },
    });
}

initStorefrontFeature('product-gallery', initProductGallery);

// Profile tabs — single active toggle within each group.
document.querySelectorAll('.part-tabs').forEach((group) => {
    const tabs = group.querySelectorAll('.part-tab');
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            tabs.forEach((t) => t.classList.remove('part-tab--active'));
            tab.classList.add('part-tab--active');
        });
    });
});

// Product option controls resolve only variants published by the server.
function initProductOptions() {
    document.querySelectorAll('[data-product-options]').forEach((form) => {
        const matrixNode = form.querySelector('[data-variant-matrix]');
        const variantInput = form.querySelector('[data-selected-variant], [data-product-variant-fallback]');
        const fallbackSelect = form.querySelector('[data-product-variant-fallback]');
        const productBuy = form.closest('.part-buy');
        const skuRow = productBuy?.querySelector('[data-selected-sku-row]');
        const sku = productBuy?.querySelector('[data-selected-sku]');
        const price = productBuy?.querySelector('[data-selected-price]');
        const stock = productBuy?.querySelector('[data-selected-stock]');
        const stockLabel = stock?.querySelector('[data-selected-stock-label]');
        const quantity = form.querySelector('[data-product-quantity]');
        const submit = form.querySelector('[data-add-to-cart]');
        const controls = [...form.querySelectorAll('[data-product-option]')];

        if (!matrixNode || !variantInput || !price || !stock || !stockLabel || !quantity || !submit) return;

        const hideSku = () => {
            if (sku) sku.textContent = '';
            if (skuRow) skuRow.hidden = true;
        };
        const renderSku = (value) => {
            const displaySku = typeof value === 'string' ? value.trim() : '';
            if (sku) sku.textContent = displaySku;
            if (skuRow) skuRow.hidden = displaySku === '';
        };
        const dispatchVariant = (variantId) => {
            document.dispatchEvent(new CustomEvent('storefront:variant-selected', {
                detail: { variantId },
            }));
        };

        let matrix;
        try {
            matrix = JSON.parse(matrixNode.textContent || '[]');
            if (!Array.isArray(matrix)) throw new TypeError('Variant matrix must be an array.');
        } catch (error) {
            console.error('[storefront:product-options] Unable to parse variant matrix.', error);
            variantInput.value = '';
            hideSku();
            quantity.value = '1';
            quantity.max = '1';
            quantity.disabled = true;
            submit.disabled = true;
            dispatchVariant('');
            return;
        }

        const selections = new Map();
        const stockModifiers = [
            'part-buy__stock--in-stock',
            'part-buy__stock--out-of-stock',
            'part-buy__stock--pre-order',
            'part-buy__stock--unavailable',
        ];
        controls.filter((control) => (
            control.matches('select')
            || control.matches('input[type="radio"]:checked')
            || control.classList.contains('part-tab--active')
        )).forEach((control) => {
            selections.set(
                Number(control.dataset.optionGroup),
                Number(control.matches('select') ? control.value : control.dataset.optionValue),
            );
        });

        const render = () => {
            const selectedVariant = fallbackSelect
                ? matrix.find((candidate) => candidate.variant_id === Number(fallbackSelect.value))
                : matrix.find((candidate) => (
                    Array.isArray(candidate.option_values)
                    && candidate.option_values.length === selections.size
                    && candidate.option_values.every((option) => selections.get(option.group_id) === option.value_id)
                ));

            if (!selectedVariant) {
                variantInput.value = '';
                dispatchVariant('');
                hideSku();
                stockLabel.textContent = stock.dataset.unavailableLabel;
                stock.classList.remove(...stockModifiers);
                stock.classList.add('part-buy__stock--unavailable');
                quantity.max = '1';
                submit.disabled = true;
                return;
            }

            const isInStock = selectedVariant.stock_status === 'in_stock';

            variantInput.value = selectedVariant.variant_id;
            dispatchVariant(String(selectedVariant.variant_id));
            renderSku(selectedVariant.sku);
            price.textContent = selectedVariant.price_label;
            stockLabel.textContent = stock.dataset[{
                in_stock: 'inStockLabel',
                out_of_stock: 'outOfStockLabel',
                pre_order: 'preOrderLabel',
            }[selectedVariant.stock_status]] || stock.dataset.unavailableLabel;
            stock.classList.remove(...stockModifiers);
            stock.classList.add(`part-buy__stock--${{
                in_stock: 'in-stock',
                out_of_stock: 'out-of-stock',
                pre_order: 'pre-order',
            }[selectedVariant.stock_status] || 'unavailable'}`);
            quantity.max = isInStock && selectedVariant.stock_quantity !== null
                ? String(Math.max(1, selectedVariant.stock_quantity))
                : '999';
            if (Number(quantity.value) > Number(quantity.max)) quantity.value = quantity.max;
            submit.disabled = !selectedVariant.purchasable;
        };

        controls.forEach((control) => {
            const eventName = control.matches('button') ? 'click' : 'change';
            control.addEventListener(eventName, () => {
                const groupId = Number(control.dataset.optionGroup);
                selections.set(groupId, Number(control.matches('select') ? control.value : control.dataset.optionValue));
                if (control.matches('button')) {
                    controls.filter((candidate) => Number(candidate.dataset.optionGroup) === groupId).forEach((candidate) => {
                        const active = candidate === control;
                        candidate.classList.toggle('part-tab--active', active);
                        candidate.setAttribute('aria-pressed', String(active));
                    });
                }
                render();
            });
        });

        fallbackSelect?.addEventListener('change', render);
        form.addEventListener('cart:request-finished', render);

        render();
    });
}

initStorefrontFeature('product-options', initProductOptions);

// Cart forms keep their ordinary POST fallback. Fetch only enhances the same
// server-authoritative endpoints with in-place feedback and totals.
const storefrontToast = document.querySelector('[data-storefront-toast]');
const storefrontToastMessage = storefrontToast?.querySelector('[data-storefront-toast-message]');
const storefrontToastLink = storefrontToast?.querySelector('[data-storefront-toast-link]');
const storefrontToastClose = storefrontToast?.querySelector('[data-storefront-toast-close]');
let storefrontToastTimer = null;

const hideStorefrontToast = () => {
    if (storefrontToastTimer) window.clearTimeout(storefrontToastTimer);
    storefrontToastTimer = null;
    if (storefrontToast) storefrontToast.hidden = true;
};

const showStorefrontToast = (message, isError = false, action = null) => {
    if (!storefrontToast || !storefrontToastMessage || !storefrontToastLink) return;

    storefrontToastMessage.textContent = message;
    storefrontToast.classList.toggle('storefront-toast--error', isError);
    storefrontToastLink.href = action?.href || storefrontToastLink.dataset.defaultHref || storefrontToastLink.href;
    storefrontToastLink.textContent = action?.label || storefrontToastLink.dataset.defaultLabel || storefrontToastLink.textContent;
    storefrontToastLink.hidden = isError;
    storefrontToast.hidden = false;
    if (storefrontToastTimer) window.clearTimeout(storefrontToastTimer);
    storefrontToastTimer = window.setTimeout(hideStorefrontToast, 4000);
};

const firstCartError = (payload) => {
    if (payload?.errors && typeof payload.errors === 'object') {
        const message = Object.values(payload.errors).flat().find((item) => typeof item === 'string');
        if (message) return message;
    }

    return typeof payload?.message === 'string'
        ? payload.message
        : 'Не удалось изменить корзину. Попробуйте ещё раз.';
};

const formatCartPrice = (value) => `${new Intl.NumberFormat('ru-RU', {
    maximumFractionDigits: 2,
}).format(Number(value) || 0)} ₽`;

const updateCartBadge = (count, pulse = false) => {
    const safeCount = Math.max(0, Number(count) || 0);

    document.querySelectorAll('[data-cart-count]').forEach((badge) => {
        badge.textContent = safeCount > 99 ? '99+' : String(safeCount);
        badge.hidden = safeCount === 0;

        const link = badge.closest('[data-cart-link]');
        link?.setAttribute('aria-label', `Корзина, товаров: ${safeCount}`);

        if (!pulse || safeCount === 0) return;
        badge.classList.remove('header__cart-badge--pulse');
        void badge.offsetWidth;
        badge.classList.add('header__cart-badge--pulse');
        window.setTimeout(() => badge.classList.remove('header__cart-badge--pulse'), 450);
    });
};

const updateCartTotals = (cart, pulse = false) => {
    if (!cart || typeof cart !== 'object') return;

    updateCartBadge(cart.items_count, pulse);
    document.querySelectorAll('[data-cart-items-count]').forEach((element) => {
        element.textContent = String(cart.items_count);
    });
    document.querySelectorAll('[data-cart-subtotal], [data-cart-total]').forEach((element) => {
        element.textContent = formatCartPrice(cart.subtotal);
    });
};

const cartRequest = async (form, data) => {
    const response = await fetch(form.action, {
        method: form.method || 'POST',
        body: data,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) throw new Error(firstCartError(payload));

    return payload;
};

const setCartAddPending = (form, button, pending) => {
    const labels = [...button.querySelectorAll('[data-cart-button-label]')];

    labels.forEach((label) => {
        if (pending) {
            label.dataset.cartOriginalLabel = label.textContent;
            label.textContent = 'Добавляем…';
        } else {
            label.textContent = label.dataset.cartOriginalLabel || label.textContent;
            delete label.dataset.cartOriginalLabel;
        }
    });

    if (pending) {
        button.dataset.cartInitiallyDisabled = String(button.disabled);
        button.dataset.cartLoading = 'true';
        button.disabled = true;
        return;
    }

    button.removeAttribute('data-cart-loading');
    button.disabled = button.dataset.cartInitiallyDisabled === 'true';
    delete button.dataset.cartInitiallyDisabled;
    form.dispatchEvent(new CustomEvent('cart:request-finished'));
};

function initCartAjax() {
    storefrontToastClose?.addEventListener('click', hideStorefrontToast);
    if (typeof window.fetch !== 'function') return;
    document.querySelectorAll('[data-cart-add]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const button = event.submitter || form.querySelector('button[type="submit"]');
            if (!(button instanceof HTMLButtonElement) || button.disabled) return;

            const source = new FormData(form);
            const data = new FormData();
            ['_token', 'product_variant_id', 'quantity'].forEach((field) => {
                const value = source.get(field);
                if (value !== null) data.append(field, value);
            });

            setCartAddPending(form, button, true);
            beginRequest('Добавляем товар…');

            try {
                const payload = await cartRequest(form, data);
                updateCartTotals(payload.cart, true);
                showStorefrontToast(payload.message || 'Товар добавлен в корзину.');
            } catch (error) {
                showStorefrontToast(error.message || 'Не удалось добавить товар в корзину.', true);
            } finally {
                setCartAddPending(form, button, false);
                endRequest();
            }
        });
    });

    const renderEmptyCart = () => {
        const content = document.querySelector('[data-cart-content]');
        const template = document.querySelector('[data-cart-empty-template]');
        if (content && template instanceof HTMLTemplateElement) {
            content.replaceChildren(template.content.cloneNode(true));
        }
    };

    document.querySelectorAll('[data-cart-update]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = event.submitter;
            if (!(button instanceof HTMLButtonElement) || button.disabled) return;

            const data = new FormData(form);
            if (button.name) data.set(button.name, button.value);
            button.disabled = true;
            button.dataset.cartLoading = 'true';
            beginRequest('Обновляем корзину…');

            try {
                const payload = await cartRequest(form, data);
                const item = form.closest('[data-cart-item]');
                const quantity = item?.querySelector('[data-cart-item-quantity]');
                const lineTotal = item?.querySelector('[data-cart-item-line-total]');
                const buttons = item?.querySelectorAll('[data-cart-update] button[name="quantity"]') || [];

                if (quantity) quantity.textContent = String(payload.item.quantity);
                if (lineTotal) lineTotal.textContent = formatCartPrice(payload.item.line_total);
                if (buttons[0]) {
                    buttons[0].value = String(Math.max(1, payload.item.quantity - 1));
                    buttons[0].disabled = payload.item.quantity <= 1;
                }
                if (buttons[1]) buttons[1].value = String(payload.item.quantity + 1);
                updateCartTotals(payload.cart);
            } catch (error) {
                showStorefrontToast(error.message || 'Не удалось изменить количество.', true);
            } finally {
                button.removeAttribute('data-cart-loading');
                const currentQuantity = Number(form.querySelector('[data-cart-item-quantity]')?.textContent || 1);
                button.disabled = button.matches('[aria-label="Убавить"]') && currentQuantity <= 1;
                endRequest();
            }
        });
    });

    document.querySelectorAll('[data-cart-remove]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = event.submitter || form.querySelector('button[type="submit"]');
            if (!(button instanceof HTMLButtonElement)) return;

            button.disabled = true;
            button.dataset.cartLoading = 'true';
            beginRequest('Удаляем товар…');

            try {
                const payload = await cartRequest(form, new FormData(form));
                form.closest('[data-cart-item]')?.remove();
                updateCartTotals(payload.cart);
                if (payload.cart.items_count === 0) renderEmptyCart();
            } catch (error) {
                button.disabled = false;
                showStorefrontToast(error.message || 'Не удалось удалить товар.', true);
            } finally {
                button.removeAttribute('data-cart-loading');
                endRequest();
            }
        });
    });

    document.querySelectorAll('[data-cart-clear]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = event.submitter || form.querySelector('button[type="submit"]');
            if (!(button instanceof HTMLButtonElement)) return;

            button.disabled = true;
            button.dataset.cartLoading = 'true';
            beginRequest('Очищаем корзину…');

            try {
                const payload = await cartRequest(form, new FormData(form));
                updateCartTotals(payload.cart);
                renderEmptyCart();
            } catch (error) {
                button.disabled = false;
                showStorefrontToast(error.message || 'Не удалось очистить корзину.', true);
            } finally {
                button.removeAttribute('data-cart-loading');
                endRequest();
            }
        });
    });
}

initStorefrontFeature('cart-ajax', initCartAjax);

const firstFavoriteError = (payload) => {
    if (payload?.errors && typeof payload.errors === 'object') {
        const message = Object.values(payload.errors).flat().find((item) => typeof item === 'string');
        if (message) return message;
    }

    return typeof payload?.message === 'string'
        ? payload.message
        : 'Не удалось изменить избранное. Попробуйте ещё раз.';
};

const updateFavoritesBadge = (count, pulse = false) => {
    const safeCount = Math.max(0, Number(count) || 0);

    document.querySelectorAll('[data-favorites-count]').forEach((badge) => {
        badge.textContent = safeCount > 99 ? '99+' : String(safeCount);
        badge.hidden = safeCount === 0;
        badge.closest('[data-favorites-link]')?.setAttribute('aria-label', `Избранное, товаров: ${safeCount}`);

        if (!pulse || safeCount === 0) return;
        badge.classList.remove('header__favorites-badge--pulse');
        void badge.offsetWidth;
        badge.classList.add('header__favorites-badge--pulse');
        window.setTimeout(() => badge.classList.remove('header__favorites-badge--pulse'), 450);
    });
};

const favoriteForms = (productId) => [...document.querySelectorAll('[data-favorite-form]')]
    .filter((form) => form.dataset.favoriteProductId === String(productId));

const setFavoritePending = (productId, pending) => {
    favoriteForms(productId).forEach((form) => {
        const button = form.querySelector('[data-favorite-toggle]');
        if (!(button instanceof HTMLButtonElement)) return;

        button.disabled = pending;
        button.toggleAttribute('aria-busy', pending);
    });
};

const setFavoriteState = (productId, isFavorite) => {
    const label = isFavorite ? 'Удалить из избранного' : 'Добавить в избранное';

    favoriteForms(productId).forEach((form) => {
        const button = form.querySelector('[data-favorite-toggle]');
        if (!(button instanceof HTMLButtonElement)) return;

        form.action = isFavorite ? form.dataset.favoriteRemoveUrl : form.dataset.favoriteAddUrl;
        let method = form.querySelector('input[name="_method"]');

        if (isFavorite && !(method instanceof HTMLInputElement)) {
            method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            form.append(method);
        }
        if (method instanceof HTMLInputElement) {
            if (isFavorite) method.value = 'DELETE';
            else method.remove();
        }

        button.classList.toggle('favorite-toggle--active', isFavorite);
        button.setAttribute('aria-pressed', String(isFavorite));
        button.setAttribute('aria-label', label);
    });
};

const renderEmptyFavorites = () => {
    const content = document.querySelector('[data-favorites-content]');
    const template = document.querySelector('[data-favorites-empty-template]');

    if (content && template instanceof HTMLTemplateElement) {
        content.replaceChildren(template.content.cloneNode(true));
    }
};

function initFavoritesAjax() {
    if (typeof window.fetch !== 'function') return;

    document.querySelectorAll('[data-favorite-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const productId = Number(form.dataset.favoriteProductId);
            const button = event.submitter || form.querySelector('[data-favorite-toggle]');
            if (!Number.isInteger(productId) || productId < 1 || !(button instanceof HTMLButtonElement) || button.disabled) return;

            setFavoritePending(productId, true);
            beginRequest('Обновляем избранное…');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(firstFavoriteError(payload));

                setFavoriteState(payload.product_id, payload.is_favorite === true);
                updateFavoritesBadge(payload.count, true);

                if (payload.is_favorite !== true) {
                    document.querySelectorAll(`[data-favorite-item="${payload.product_id}"]`).forEach((item) => item.remove());
                    if (Number(payload.count) === 0) renderEmptyFavorites();
                }

                showStorefrontToast(payload.message || 'Избранное обновлено.', false, {
                    href: form.dataset.favoritesUrl || '/favorites',
                    label: 'Перейти в избранное',
                });
            } catch (error) {
                showStorefrontToast(error.message || 'Не удалось изменить избранное.', true);
            } finally {
                setFavoritePending(productId, false);
                endRequest();
            }
        });
    });
}

initStorefrontFeature('favorites-ajax', initFavoritesAjax);


// Checkout totals are a visual estimate; CheckoutService remains authoritative.
document.querySelectorAll('.checkout-layout').forEach((form) => {
    const deliveryOutput = form.querySelector('[data-checkout-delivery]');
    const totalOutput = form.querySelector('[data-checkout-total]');
    const totalLabel = form.querySelector('[data-checkout-total-label]');
    const deliveryInputs = [...form.querySelectorAll('[data-delivery-price]')];

    if (!deliveryOutput || !totalOutput || deliveryInputs.length === 0) return;

    const subtotal = Number(totalOutput.dataset.checkoutSubtotal);
    const formatPrice = (value) => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value)} ₽`;
    const renderCheckoutTotal = () => {
        const selected = deliveryInputs.find((input) => input.checked);

        if (!selected) {
            deliveryOutput.textContent = '—';
            if (totalLabel) totalLabel.textContent = 'Сумма товаров';
            totalOutput.textContent = formatPrice(subtotal);
            return;
        }

        const deliveryPrice = Number(selected.dataset.deliveryPrice);
        const priceMode = selected.dataset.deliveryPriceMode;

        if (priceMode === 'on_request') {
            deliveryOutput.textContent = 'Стоимость уточнит менеджер';
            if (totalLabel) totalLabel.textContent = 'Сумма товаров (без доставки)';
            totalOutput.textContent = formatPrice(subtotal);
            return;
        }

        deliveryOutput.textContent = priceMode === 'free' ? 'Бесплатно' : formatPrice(deliveryPrice);
        if (totalLabel) totalLabel.textContent = 'Итого';
        totalOutput.textContent = formatPrice(subtotal + deliveryPrice);
    };

    deliveryInputs.forEach((input) => input.addEventListener('change', renderCheckoutTotal));
    renderCheckoutTotal();
});

// FAQ page category tabs — switch the active pill and its question panel.
document.querySelectorAll('[data-faq-tabs]').forEach((group) => {
    const tabs = group.querySelectorAll('[data-faq-tab]');
    const panels = document.querySelectorAll('[data-faq-panel]');

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            tabs.forEach((t) => t.classList.remove('faq-page__tab--active'));
            tab.classList.add('faq-page__tab--active');

            panels.forEach((panel) => {
                panel.classList.toggle('faq-page__list--hidden', panel.dataset.faqPanel !== tab.dataset.faqTab);
            });
        });
    });
});

// FAQ accordion.
document.querySelectorAll('[data-faq-toggle]').forEach((toggle) => {
    const item = toggle.closest('[data-faq-item]');
    if (!item) return;

    toggle.addEventListener('click', () => {
        const open = item.classList.toggle('faq__item--open');
        toggle.setAttribute('aria-expanded', String(open));
    });
});

// Inquiry forms remain ordinary POST forms; JavaScript only adds modal and AJAX progress.
function createInquiryModalController(modal) {
    const dialog = modal.querySelector('[role="dialog"]');
    let returnFocus = null;

    if (!dialog) return null;

    const focusableSelector = [
        'a[href]:not([tabindex="-1"])',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"]):not([tabindex="-1"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(', ');
    const getFocusableElements = () => [...dialog.querySelectorAll(focusableSelector)].filter((element) => {
        if (element.hidden || element.getAttribute('aria-hidden') === 'true' || element.closest('[hidden], [aria-hidden="true"]')) {
            return false;
        }

        const style = window.getComputedStyle(element);
        return style.display !== 'none' && style.visibility !== 'hidden';
    });
    const isOpen = () => modal.classList.contains('inquiry-modal--open');
    const clearInquiryHash = () => {
        if (['#storefront-inquiry', '#storefront-inquiry-success'].includes(window.location.hash)) {
            window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);
        }
    };
    const open = (trigger = null) => {
        if (trigger instanceof HTMLElement) returnFocus = trigger;
        clearInquiryHash();
        modal.classList.add('inquiry-modal--open');
        modal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => {
            clearInquiryHash();
            dialog.focus();
        }, 0);
    };
    const close = (restoreFocus = true) => {
        modal.classList.remove('inquiry-modal--open');
        modal.setAttribute('aria-hidden', 'true');
        clearInquiryHash();

        if (restoreFocus && returnFocus?.isConnected) returnFocus.focus();
    };

    modal.querySelectorAll('[data-inquiry-close]').forEach((control) => {
        control.addEventListener('click', (event) => {
            event.preventDefault();
            close();
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            event.preventDefault();
            close();
            return;
        }

        if (event.key !== 'Tab' || !isOpen()) return;

        const focusableElements = getFocusableElements();
        if (focusableElements.length === 0) {
            event.preventDefault();
            dialog.focus();
            return;
        }

        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];
        const activeElement = document.activeElement;

        if (!dialog.contains(activeElement)) {
            event.preventDefault();
            (event.shiftKey ? lastFocusable : firstFocusable).focus();
            return;
        }

        if (event.shiftKey && (activeElement === firstFocusable || activeElement === dialog)) {
            event.preventDefault();
            lastFocusable.focus();
        } else if (!event.shiftKey && activeElement === lastFocusable) {
            event.preventDefault();
            firstFocusable.focus();
        }
    });

    const requestedByHash = modal.id !== '' && window.location.hash === `#${modal.id}`;

    if (modal.hasAttribute('data-inquiry-auto-open') || requestedByHash) {
        open();
    } else {
        modal.setAttribute('aria-hidden', 'true');
    }

    return { open, close, isOpen, returnFocus: () => returnFocus };
}

function initInquiryForms() {
    const successModal = document.querySelector('[data-inquiry-success-modal]');
    const successController = successModal ? createInquiryModalController(successModal) : null;

    document.querySelectorAll('[data-inquiry-modal]').forEach((modal) => {
        const form = modal.querySelector('[data-inquiry-form]');
        const result = modal.querySelector('[data-inquiry-result]');
        const submit = modal.querySelector('[data-inquiry-submit]');
        const submitLabel = modal.querySelector('[data-inquiry-submit-label]');
        const modalController = createInquiryModalController(modal);

        if (!form || !result || !submit || !submitLabel || !modalController) return;

        const selectedProductVariant = () => document.querySelector(
            '[data-selected-variant], [data-product-variant-fallback], [data-product-options] input[name="product_variant_id"]',
        )?.value;

        const syncProductVariant = () => {
            const input = form.querySelector('[data-inquiry-product-variant]');
            const variantId = selectedProductVariant();
            if (input) input.value = variantId || '';
        };

        document.addEventListener('storefront:variant-selected', (event) => {
            const input = form.querySelector('[data-inquiry-product-variant]');
            if (input) input.value = event.detail?.variantId || '';
        });

        document.querySelectorAll('[data-inquiry-open]').forEach((trigger) => {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                syncProductVariant();
                modalController.open(trigger);
            });
        });

        form.addEventListener('submit', async (event) => {
            syncProductVariant();

            if (form.dataset.ordinaryRetry === 'true') return;

            event.preventDefault();
            submit.disabled = true;
            submit.dataset.loading = 'true';
            submitLabel.textContent = 'Отправляем…';
            result.replaceChildren();
            beginRequest('Отправляем заявку…');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const messages = payload.errors
                        ? Object.values(payload.errors).flat()
                        : [payload.message || 'Не удалось отправить заявку. Проверьте данные.'];
                    const list = document.createElement('ul');
                    list.className = 'inquiry-modal__errors';
                    messages.forEach((message) => {
                        const item = document.createElement('li');
                        item.textContent = message;
                        list.append(item);
                    });
                    result.append(list);
                    return;
                }

                form.reset();
                syncProductVariant();
                delete form.dataset.ordinaryRetry;
                const trigger = modalController.returnFocus();
                modalController.close(false);
                successController?.open(trigger);
            } catch (_error) {
                const warning = document.createElement('p');
                warning.className = 'inquiry-modal__errors';
                warning.textContent = 'Нет соединения. Нажмите кнопку ещё раз, чтобы повторить отправку обычным способом.';
                result.append(warning);
                form.dataset.ordinaryRetry = 'true';
                submitLabel.textContent = 'Повторить обычной отправкой';
            } finally {
                submit.disabled = false;
                submit.removeAttribute('data-loading');
                if (form.dataset.ordinaryRetry !== 'true') submitLabel.textContent = 'Отправить заявку';
                endRequest();
            }
        });
    });
}

initStorefrontFeature('inquiry', initInquiryForms);

// Ordinary navigation remains browser-native; this only provides progress feedback.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    window.setTimeout(() => {
        if (event.defaultPrevented || !form.isConnected) return;

        form.dataset.requestPending = 'true';
        form.setAttribute('aria-busy', 'true');
        if (event.submitter instanceof HTMLButtonElement) {
            event.submitter.dataset.loading = 'true';
            event.submitter.setAttribute('aria-disabled', 'true');
        }
        beginRequest(form.dataset.loadingLabel || 'Загружаем страницу…');
    }, 0);
});

document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!(event.target instanceof Element)) return;

    const link = event.target.closest('a[href]');
    if (!link || link.matches('[data-inquiry-open], [download]')) return;
    if (link.target && link.target !== '_self') return;

    const href = link.getAttribute('href')?.trim() || '';
    if (href === '' || href.startsWith('#') || href.toLowerCase().startsWith('javascript:')) return;

    const url = new URL(link.href, window.location.href);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== window.location.origin) return;
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

    link.dataset.requestPending = 'true';
    link.setAttribute('aria-busy', 'true');
    beginRequest('Загружаем страницу…');
});

window.addEventListener('pageshow', resetRequestUi);

// Burger toggles the mobile menu dropdown.
const mobileMenu = document.querySelector('[data-mobile-menu]');

document.querySelectorAll('[data-burger]').forEach((burger) => {
    const setOpen = (open) => {
        burger.classList.toggle('active', open);
        burger.setAttribute('aria-expanded', String(open));
        mobileMenu?.classList.toggle('mobile-menu--open', open);
    };

    burger.addEventListener('click', (event) => {
        event.stopPropagation();
        setOpen(!burger.classList.contains('active'));
    });

    mobileMenu?.querySelector('[data-mobile-menu-close]')?.addEventListener('click', () => setOpen(false));

    mobileMenu?.querySelectorAll('.mobile-menu__link').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });

    document.addEventListener('click', (event) => {
        if (mobileMenu?.classList.contains('mobile-menu--open') && !mobileMenu.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileMenu?.classList.contains('mobile-menu--open')) {
            setOpen(false);
        }
    });
});

// Catalog sidebar.
// Tablet (≤1200px): "Свернуть/Развернуть" collapses the list inline.
// Mobile (≤768px): the sidebar is a dropdown under the trigger — the trigger
// toggles it, clicking outside or Esc closes it.
const isMobile = window.matchMedia('(max-width: 768px)');

document.querySelectorAll('[data-catalog-nav]').forEach((nav) => {
    const toggle = nav.querySelector('[data-catalog-toggle]');
    const triggers = [...document.querySelectorAll('[data-catalog-open]')];

    const setDropdown = (open) => {
        nav.toggleAttribute('data-open', open);
        triggers.forEach((btn) => btn.setAttribute('aria-expanded', String(open)));
    };

    const closeDropdown = () => setDropdown(false);

    triggers.forEach((btn) => {
        btn.addEventListener('click', () => setDropdown(!nav.hasAttribute('data-open')));
    });

    // A click anywhere outside the dropdown and its trigger closes it.
    document.addEventListener('click', (event) => {
        if (!nav.hasAttribute('data-open')) return;
        if (nav.contains(event.target)) return;
        if (triggers.some((btn) => btn.contains(event.target))) return;
        closeDropdown();
    });

    // Esc closes the dropdown.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && nav.hasAttribute('data-open')) closeDropdown();
    });

    // Picking a category closes it (AJAX load will hook in here later).
    nav.querySelectorAll('.catalog-nav__link').forEach((link) => {
        link.addEventListener('click', () => {
            if (isMobile.matches) closeDropdown();
        });
    });

    if (toggle) {
        toggle.addEventListener('click', () => {
            if (isMobile.matches) {
                closeDropdown();
                return;
            }
            const collapsed = nav.toggleAttribute('data-collapsed');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.textContent = collapsed ? 'Развернуть' : 'Свернуть';
        });
    }
});
