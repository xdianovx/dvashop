// Burger toggle. Mobile menu open/close will hook onto this state later.
document.querySelectorAll('[data-burger]').forEach((burger) => {
    burger.addEventListener('click', () => {
        const isOpen = burger.classList.toggle('active');
        burger.setAttribute('aria-expanded', String(isOpen));
    });
});
