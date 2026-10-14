// Requires the disposable catalog-prices-server.php fixture on localhost:18089.
const { chromium, expect } = require(process.env.PLAYWRIGHT_MODULE || 'playwright/test');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'msedge', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    async function shot(name) {
        await page.waitForTimeout(350); // Allow Filament theme, dropdown and sidebar transitions to settle.
        if (process.env.SCREENSHOTS) await page.screenshot({ path: path.join(process.env.SCREENSHOTS, name + '.png') });
    }
    try {
        await page.goto('http://127.0.0.1:18089/__pricing-login');
        await page.locator('.fi-select-input-btn').nth(1).click();
        await page.getByRole('option', { name: 'Тестовые детали', exact: true }).click();
        await page.locator('[id="form.price"]').fill('250');
        await page.getByRole('button', { name: 'Рассчитать предварительный просмотр', exact: true }).click();
        await page.getByText('Расчёт актуален', { exact: true }).waitFor();
        const first = page.getByRole('combobox', { name: 'Решение для товара #1', exact: true });
        await expect(first).toHaveValue('0');
        await page.getByRole('button', { name: 'Заменить все', exact: true }).click();
        await expect(first).toHaveValue('1');
        assert((await page.locator('.cp-table select').evaluateAll(es => es.every(e => e.value === '1'))));
        await first.selectOption('0');
        await expect(page.locator('.cp-table').getByText('Индивидуальное решение', { exact: true })).toHaveCount(1);
        await page.getByRole('button', { name: 'Перейти к странице 2', exact: true }).click();
        await expect(first).toHaveCount(0);
        await page.locator('#pricing-search').fill('Деталь для длинного названия');
        await expect(first).toHaveValue('0');
        await page.locator('#pricing-search').fill('');
        await expect(page.locator('.cp-table select')).toHaveCount(20);
        await expect(first).toHaveValue('0');
        await page.getByRole('button', { name: 'Сохранить все', exact: true }).click();
        await expect(page.locator('.cp-table').getByText('Индивидуальное решение', { exact: true })).toHaveCount(0);
        assert((await page.locator('.cp-table select').evaluateAll(es => es.every(e => e.value === '0'))));
        await page.getByRole('button', { name: 'Заменить все', exact: true }).click();
        await expect(first).toHaveValue('1');
        await first.selectOption('0');
        await expect(page.locator('.cp-table').getByText('Индивидуальное решение', { exact: true })).toHaveCount(1);
        await page.getByText('3. Исключения', { exact: true }).evaluate(el => el.scrollIntoView({ block: 'start' }));
        await shot('pricing-light-desktop');
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('theme-changed', { detail: 'dark' })));
        await expect(page.locator('html')).toHaveClass(/dark/);
        await shot('pricing-dark-desktop');
        const optionColors = await first.locator('option').first().evaluate(el => ({ background: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color }));
        assert.notEqual(optionColors.background, optionColors.color);
        await page.locator('.fi-select-input-btn').first().scrollIntoViewIfNeeded();
        await page.locator('.fi-select-input-btn').first().click();
        await page.getByRole('option', { name: 'Принятие исторических цен', exact: true }).waitFor();
        await shot('pricing-dark-dropdown');
        await page.keyboard.press('Escape');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.getByText('3. Исключения', { exact: true }).scrollIntoViewIfNeeded();
        await shot('pricing-dark-mobile');
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Page overflows mobile viewport');
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('theme-changed', { detail: 'light' })));
        await expect(page.locator('html')).not.toHaveClass(/dark/);
        await shot('pricing-light-mobile');
        await page.getByText('4. Подтверждение', { exact: true }).scrollIntoViewIfNeeded();
        await shot('pricing-confirm-mobile');
        if (!process.env.SKIP_CONFIRM) {
        await page.getByRole('button', { name: 'Подтвердить изменения', exact: true }).click();
        await page.getByText('Операция поставлена в очередь', { exact: true }).waitFor();
        }
        assert.equal(errors.length, 0, errors.join('\n'));
        console.log(JSON.stringify({ result: 'PASS', cases: ['1949 rows', 'mass decisions', 'row priority', 'pagination', 'search', 'light/dark', 'desktop/mobile', 'dropdown', ...(process.env.SKIP_CONFIRM ? [] : ['confirmation'])], javascriptErrors: errors, optionColors }));
    } catch (error) {
        console.error((await page.locator('body').innerText()).slice(-6000));
        await shot('pricing-failure');
        throw error;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
