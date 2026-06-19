// Burger toggle. Mobile menu open/close will hook onto this state later.
document.querySelectorAll('[data-burger]').forEach((burger) => {
    burger.addEventListener('click', () => {
        const isOpen = burger.classList.toggle('active');
        burger.setAttribute('aria-expanded', String(isOpen));
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
