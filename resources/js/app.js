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
// Mobile (≤768px): the sidebar is a popup — trigger opens it, "Свернуть"
// and a backdrop click close it.
const isMobile = window.matchMedia('(max-width: 768px)');

document.querySelectorAll('[data-catalog-nav]').forEach((nav) => {
    const toggle = nav.querySelector('[data-catalog-toggle]');

    const openPopup = () => {
        nav.setAttribute('data-open', '');
        document.body.classList.add('is-scroll-locked');
    };

    const closePopup = () => {
        nav.removeAttribute('data-open');
        document.body.classList.remove('is-scroll-locked');
    };

    document.querySelectorAll('[data-catalog-open]').forEach((btn) => {
        btn.addEventListener('click', openPopup);
    });

    // Backdrop click (outside the panel) closes the popup.
    nav.addEventListener('click', (event) => {
        if (event.target === nav) closePopup();
    });

    // Esc closes the popup.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && nav.hasAttribute('data-open')) closePopup();
    });

    // Picking a category closes the popup (AJAX load will hook in here later).
    nav.querySelectorAll('.catalog-nav__link').forEach((link) => {
        link.addEventListener('click', () => {
            if (isMobile.matches) closePopup();
        });
    });

    if (toggle) {
        toggle.addEventListener('click', () => {
            if (isMobile.matches) {
                closePopup();
                return;
            }
            const collapsed = nav.toggleAttribute('data-collapsed');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.textContent = collapsed ? 'Развернуть' : 'Свернуть';
        });
    }
});
