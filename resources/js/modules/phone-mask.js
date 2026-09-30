const PHONE_SELECTOR = 'input[type="tel"]';
const PHONE_PREFIX = '+7';
const LOCAL_DIGITS = 10;

const extractLocalDigits = (value) => {
    const trimmed = value.trim();
    let digits = (trimmed.startsWith(PHONE_PREFIX) ? trimmed.slice(PHONE_PREFIX.length) : trimmed).replace(/\D+/g, '');

    if (digits.length > LOCAL_DIGITS && (digits.startsWith('7') || digits.startsWith('8'))) {
        digits = digits.slice(1);
    }

    return digits.slice(0, LOCAL_DIGITS);
};

const formatPhone = (digits) => {
    if (digits === '') {
        return '';
    }

    let result = `${PHONE_PREFIX}(${digits.slice(0, 3)}`;
    if (digits.length > 3) result += `) ${digits.slice(3, 6)}`;
    if (digits.length > 6) result += ` ${digits.slice(6, 8)}`;
    if (digits.length > 8) result += ` ${digits.slice(8, 10)}`;

    return result;
};

const applyMask = (input) => {
    const formatted = formatPhone(extractLocalDigits(input.value));
    if (input.value !== formatted) {
        input.value = formatted;
    }
};

export const initPhoneMask = () => {
    document.querySelectorAll(PHONE_SELECTOR).forEach(applyMask);

    document.addEventListener('input', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.matches(PHONE_SELECTOR)) {
            applyMask(event.target);
        }
    });

    document.addEventListener('focusin', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.matches(PHONE_SELECTOR) && event.target.value === '') {
            event.target.value = `${PHONE_PREFIX}(`;
        }
    });

    document.addEventListener('focusout', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.matches(PHONE_SELECTOR) && extractLocalDigits(event.target.value) === '') {
            event.target.value = '';
        }
    });
};
