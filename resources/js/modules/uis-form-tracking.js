const UIS_WAIT_TIMEOUT_MS = 5000;
const UIS_POLL_INTERVAL_MS = 200;
const UIS_STORAGE_PREFIX = 'dvashop:uis:';
const trackedCorrelationKeys = new Set();

const asString = (value) => (typeof value === 'string' ? value.trim() : '');

const normalizePayload = (payload) => ({
    correlationKey: asString(payload?.correlationKey),
    name: asString(payload?.name),
    email: asString(payload?.email),
    phone: asString(payload?.phone),
    message: asString(payload?.message),
});

const storageHas = (correlationKey) => {
    try {
        return window.sessionStorage.getItem(`${UIS_STORAGE_PREFIX}${correlationKey}`) === '1';
    } catch (_error) {
        return false;
    }
};

const storageMark = (correlationKey) => {
    try {
        window.sessionStorage.setItem(`${UIS_STORAGE_PREFIX}${correlationKey}`, '1');
    } catch (_error) {
        // Privacy mode and blocked storage must never affect the storefront flow.
    }
};

const waitForComagic = () => new Promise((resolve) => {
    const deadline = Date.now() + UIS_WAIT_TIMEOUT_MS;

    const check = () => {
        try {
            if (typeof window.Comagic?.addOfflineRequest === 'function') {
                resolve(true);
                return;
            }
        } catch (_error) {
            resolve(false);
            return;
        }

        if (Date.now() >= deadline) {
            resolve(false);
            return;
        }

        window.setTimeout(check, UIS_POLL_INTERVAL_MS);
    };

    check();
});

const sendUisOfflineRequest = async (payload) => {
    try {
        const normalized = normalizePayload(payload);
        const hasContactContext = normalized.name !== '' || normalized.email !== '' || normalized.message !== '';

        if (normalized.correlationKey === '' || normalized.phone === '' || !hasContactContext) return;
        if (trackedCorrelationKeys.has(normalized.correlationKey) || storageHas(normalized.correlationKey)) return;

        trackedCorrelationKeys.add(normalized.correlationKey);

        if (!await waitForComagic()) return;

        const result = window.Comagic.addOfflineRequest({
            name: normalized.name,
            email: normalized.email,
            phone: normalized.phone,
            message: normalized.message,
        });
        if (result && typeof result.catch === 'function') result.catch(() => {});
        storageMark(normalized.correlationKey);
    } catch (_error) {
        // UIS is optional analytics. Its failures are intentionally invisible to users.
    }
};

export function trackUisOfflineRequest(payload) {
    try {
        void sendUisOfflineRequest(payload);
    } catch (_error) {
        // Keep this public hook fail-open even for unexpected synchronous failures.
    }
}

const trackServerConfirmedPagePayload = () => {
    document.querySelectorAll('[data-uis-success-payload]').forEach((element) => {
        try {
            trackUisOfflineRequest(JSON.parse(element.textContent || ''));
        } catch (_error) {
            // A malformed optional payload must not affect page initialization.
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackServerConfirmedPagePayload, { once: true });
} else {
    trackServerConfirmedPagePayload();
}
