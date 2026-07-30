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
