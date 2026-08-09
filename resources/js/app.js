import Swiper from 'swiper';
import { Thumbs, Pagination } from 'swiper/modules';
import 'swiper/css';
import 'swiper/css/thumbs';
import 'swiper/css/pagination';

// Product gallery: main slider synced with thumbnails.
if (document.querySelector('[data-gallery-main]')) {
    const thumbs = new Swiper('[data-gallery-thumbs]', {
        slidesPerView: 4,
        spaceBetween: 10,
        watchSlidesProgress: true,
    });

    new Swiper('[data-gallery-main]', {
        modules: [Thumbs, Pagination],
        thumbs: { swiper: thumbs },
        pagination: { el: '.part-gallery__pagination', clickable: true },
    });
}

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
document.querySelectorAll('[data-product-options]').forEach((form) => {
    const matrixNode = form.querySelector('[data-variant-matrix]');
    const variantInput = form.querySelector('[data-selected-variant], [data-product-variant-fallback]');
    const fallbackSelect = form.querySelector('[data-product-variant-fallback]');
    const sku = form.closest('.part-buy')?.querySelector('[data-selected-sku]');
    const price = form.closest('.part-buy')?.querySelector('[data-selected-price]');
    const stock = form.closest('.part-buy')?.querySelector('[data-selected-stock]');
    const stockLabel = stock?.querySelector('[data-selected-stock-label]');
    const quantity = form.querySelector('[data-product-quantity]');
    const submit = form.querySelector('[data-add-to-cart]');
    const controls = [...form.querySelectorAll('[data-product-option]')];

    if (!matrixNode || !variantInput || !sku || !price || !stock || !stockLabel || !quantity || !submit) return;

    const matrix = JSON.parse(matrixNode.textContent || '[]');
    const selections = new Map();
    const stockModifiers = [
        'part-buy__stock--in-stock',
        'part-buy__stock--out-of-stock',
        'part-buy__stock--pre-order',
        'part-buy__stock--unavailable',
    ];
    const formatPrice = (value) => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value))} руб.`;

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
                candidate.option_values.length === selections.size
                && candidate.option_values.every((option) => selections.get(option.group_id) === option.value_id)
            ));

        if (!selectedVariant) {
            variantInput.value = '';
            sku.textContent = '—';
            stockLabel.textContent = stock.dataset.unavailableLabel;
            stock.classList.remove(...stockModifiers);
            stock.classList.add('part-buy__stock--unavailable');
            quantity.max = '1';
            submit.disabled = true;
            return;
        }

        const isInStock = selectedVariant.stock_status === 'in_stock';
        const isOutOfStock = selectedVariant.stock_status === 'out_of_stock';
        const hasNoStock = isInStock && selectedVariant.stock_quantity !== null && selectedVariant.stock_quantity <= 0;

        variantInput.value = selectedVariant.variant_id;
        sku.textContent = selectedVariant.sku;
        price.textContent = formatPrice(selectedVariant.price);
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
        submit.disabled = isOutOfStock || hasNoStock;
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

    render();
});

// Checkout totals are a visual estimate; CheckoutService remains authoritative.
document.querySelectorAll('.checkout-layout').forEach((form) => {
    const deliveryOutput = form.querySelector('[data-checkout-delivery]');
    const totalOutput = form.querySelector('[data-checkout-total]');
    const deliveryInputs = [...form.querySelectorAll('[data-delivery-price]')];

    if (!deliveryOutput || !totalOutput || deliveryInputs.length === 0) return;

    const subtotal = Number(totalOutput.dataset.checkoutSubtotal);
    const formatPrice = (value) => `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value)} ₽`;
    const renderCheckoutTotal = () => {
        const selected = deliveryInputs.find((input) => input.checked);

        if (!selected) {
            deliveryOutput.textContent = '—';
            totalOutput.textContent = formatPrice(subtotal);
            return;
        }

        const deliveryPrice = Number(selected.dataset.deliveryPrice);
        deliveryOutput.textContent = formatPrice(deliveryPrice);
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
