/**
 * Screenshots for docs/manual/USER-MANUAL.md, taken against the demo app.
 *
 *   1. APP_ENV=manual php artisan migrate:fresh --seed --force && APP_ENV=manual php docs/manual/tools/demo-data.php
 *   2. APP_ENV=manual php -S 127.0.0.1:8002 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
 *   3. npm i --prefix <tmp> playwright@1.48.2 && npx --prefix <tmp> playwright install chromium
 *      NODE_PATH=<tmp>/node_modules node docs/manual/tools/capture.cjs
 *
 * Desktop screens at 1366 px wide; Pick & Pack at phone width (390 px).
 */
const { chromium } = require('playwright');
const crypto = require('crypto');
const path = require('path');
const { execFileSync } = require('child_process');

const BASE = process.env.MANUAL_URL || 'http://127.0.0.1:8002';
const OUT = path.resolve(__dirname, '../img');
const ROOT = path.resolve(__dirname, '../../..');
const PASSWORD = 'demo-password-1';
const ADMIN_SECRET = 'JBSWY3DPEHPK3PXP';

function totp(secret, offset = 0) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + offset));
    const h = crypto.createHmac('sha1', key).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1e6).padStart(6, '0');
}

function lookup(...args) {
    return execFileSync('php', [path.join(__dirname, 'lookup.php'), ...args], { cwd: ROOT, env: { ...process.env, APP_ENV: 'manual' } })
        .toString()
        .trim();
}

async function shot(page, name, opts = {}) {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: opts.fullPage ?? false, clip: opts.clip });
    console.log('  ', name);
}

async function newPage(browser, phone = false) {
    const ctx = await browser.newContext({
        viewport: phone ? { width: 390, height: 844 } : { width: 1366, height: 900 },
        deviceScaleFactor: phone ? 2 : 1,
        isMobile: phone,
        hasTouch: phone,
        colorScheme: 'light',
        locale: 'en-AU',
        timezoneId: 'Australia/Brisbane',
    });
    return ctx.newPage();
}

async function login(page, email, { secret, shootChallenge } = {}) {
    await page.goto(`${BASE}/login`);
    await page.fill('#email', email);
    await page.fill('#password', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    if (page.url().includes('two-factor-challenge')) {
        if (shootChallenge) await shot(page, shootChallenge);
        await page.fill('input[autocomplete="one-time-code"]', totp(secret));
        await page.click('button[type=submit]');
        await page.waitForURL((u) => !u.pathname.startsWith('/two-factor-challenge'));
    }
    await page.waitForLoadState('networkidle');
}

(async () => {
    const browser = await chromium.launch();

    // --- Getting started -------------------------------------------------------
    console.log('Getting started');
    let page = await newPage(browser);
    await page.goto(`${BASE}/login`);
    await shot(page, '01-login');

    const token = lookup('invite', `drew.${Date.now()}@example.com`);
    await page.goto(`${BASE}/invitations/${token}`);
    await page.fill('#name', 'Drew Example');
    await shot(page, '02-accept-invitation');

    // 2FA set-up, as the read-only user (optional for them, so it isn't forced)
    await login(page, 'riley.viewer@example.com');
    await page.goto(`${BASE}/settings/two-factor`);
    await shot(page, '03-2fa-start');
    await page.click('text=Set up two-factor');
    await page.getByText('Confirm your password').or(page.getByText('Or enter this key manually')).first().waitFor();
    if (page.url().includes('confirm-password')) {
        await shot(page, '04-confirm-password');
        await page.fill('#password', PASSWORD);
        await page.press('#password', 'Enter');
        await page.waitForURL(/settings\/two-factor/);
        const setUp = page.getByRole('button', { name: 'Set up two-factor' });
        await setUp.or(page.getByText('Or enter this key manually')).first().waitFor();
        if (await setUp.isVisible()) await setUp.click();
    }
    await page.waitForSelector('text=Or enter this key manually');
    await shot(page, '05-2fa-qr');
    const secret = (await page.textContent('code.font-mono')).trim();
    await page.fill('input[autocomplete="one-time-code"]', totp(secret));
    await page.click('button:has-text("Confirm")');
    await page.waitForSelector('text=Two-factor authentication is on');
    await page.click('text=Show recovery codes');
    await page.waitForSelector('text=Recovery codes:');
    await shot(page, '06-2fa-recovery-codes');
    await page.context().close();

    // --- Admin / manager desktop screens -----------------------------------------
    console.log('Desktop (admin)');
    page = await newPage(browser);
    await login(page, 'alex.admin@example.com', { secret: ADMIN_SECRET, shootChallenge: '07-2fa-challenge' });
    await shot(page, '10-dashboard', { fullPage: true });

    await page.goto(`${BASE}/products`);
    await shot(page, '20-products');
    const productId = lookup('id', 'products', 'sku', 'HCB-ORIG-250');
    await page.goto(`${BASE}/products/${productId}/edit`);
    await shot(page, '21-product-form', { fullPage: true });
    await page.goto(`${BASE}/product-packs`);
    await shot(page, '22-pack-setup', { fullPage: true });
    await page.goto(`${BASE}/product-costs`);
    await shot(page, '23-product-costs', { fullPage: true });

    await page.goto(`${BASE}/locations`);
    await page.click('tr:has-text("A-01-1") >> text=Edit');
    await shot(page, '30-locations', { fullPage: true });

    await page.goto(`${BASE}/suppliers`);
    await shot(page, '40-suppliers');
    await page.goto(`${BASE}/purchase-orders?status=all`);
    await shot(page, '41-purchase-orders');
    const poId = (n) => lookup('id', 'purchase_orders', 'number', n);
    await page.goto(`${BASE}/purchase-orders/${poId('PO-2026-00004')}/edit`);
    await shot(page, '42-po-draft-form', { fullPage: true });
    await page.goto(`${BASE}/purchase-orders/${poId('PO-2026-00004')}`);
    await page.click('text=Email supplier');
    await shot(page, '43-po-email', { fullPage: true });
    await page.goto(`${BASE}/purchase-orders/${poId('PO-2026-00002')}`);
    await shot(page, '44-po-partially-received', { fullPage: true });
    await page.goto(`${BASE}/purchase-orders/${poId('PO-2026-00002')}/receive`);
    const rows = page.locator('tbody tr');
    await rows.nth(0).locator('input[type=number]').nth(0).fill('2');
    await rows.nth(0).locator('input[placeholder=Lot]').fill('NOM-1029');
    await rows.nth(0).locator('input[type=date]').fill('2026-11-16');
    await rows.nth(3).locator('input[type=number]').nth(0).fill('14');
    await rows.nth(3).locator('input[placeholder=Lot]').fill('CPJ-1027');
    await rows.nth(3).locator('input[type=date]').fill('2026-11-09');
    await shot(page, '45-receive', { fullPage: true });

    await page.goto(`${BASE}/stock`);
    await shot(page, '50-stock-on-hand');
    await page.goto(`${BASE}/stock/${productId}`);
    await shot(page, '51-stock-product', { fullPage: true });
    await page.goto(`${BASE}/stock/movements`);
    await shot(page, '52-movements');

    await page.goto(`${BASE}/stocktakes`);
    await shot(page, '60-stocktakes');
    const counting = lookup('id', 'stocktakes', 'status', 'counting');
    await page.goto(`${BASE}/stocktakes/${counting}`);
    await shot(page, '61-stocktake-counting', { fullPage: true });

    await page.goto(`${BASE}/orders`);
    await shot(page, '70-orders');
    const orderId = (n) => lookup('id', 'orders', 'number', n);
    await page.goto(`${BASE}/orders/${orderId('SO-2026-00008')}`);
    await shot(page, '71-order-oversold', { fullPage: true });
    await page.goto(`${BASE}/orders/${orderId('SO-2026-00001')}`);
    await shot(page, '72-order-ordermentum', { fullPage: true });
    await page.goto(`${BASE}/orders/create`);
    await shot(page, '73-order-new');
    const custId = lookup('id', 'customers', 'code', 'C-BEACH');
    await page.goto(`${BASE}/customers/${custId}/edit`);
    await shot(page, '74-customer', { fullPage: true });

    await page.goto(`${BASE}/admin/delivery-areas`);
    await page.click('tr:has-text("Zone 1") >> text=Edit');
    await shot(page, '90-delivery-areas', { fullPage: true });

    await page.goto(`${BASE}/sync`);
    await shot(page, '95-sync', { fullPage: true });
    // Every write/retry/replay asks first. Native dialogs can't be screenshotted: print and dismiss them.
    page.on('dialog', async (d) => {
        console.log(`   dialog: ${d.message().replace(/\n+/g, ' | ')}`);
        await d.dismiss();
    });
    for (const label of ['Retry all', 'Push now', 'Replay', 'Enable all', 'Enable all portal listings']) {
        await page.getByRole('button', { name: label, exact: true }).first().click();
        await page.waitForTimeout(300);
    }
    await page.locator('tr:has-text("HCB-ORIG-250") button:has-text("on")').first().click();
    await page.waitForTimeout(300);

    await page.goto(`${BASE}/admin/users`);
    await shot(page, '96-users', { fullPage: true });
    await page.click('tr:has-text("sam.picker@example.com") >> text=Change');
    await page.locator('tr:has-text("Sam Picker") input[type=email]').fill('sam.p@example.com');
    await shot(page, '96b-user-email');
    await page.goto(`${BASE}/admin/settings`);
    await shot(page, '97-company-settings', { fullPage: true });
    await page.goto(`${BASE}/admin/audit-log`);
    await shot(page, '98-audit-log');
    await page.context().close();

    // Warehouse staff: desktop dashboard (what they see)
    page = await newPage(browser);
    await login(page, 'sam.picker@example.com');
    await shot(page, '11-dashboard-staff');
    await page.context().close();

    // --- Pick & pack on a phone -----------------------------------------------
    console.log('Phone (pick & pack)');
    page = await newPage(browser, true);
    await login(page, 'sam.picker@example.com');
    await page.goto(`${BASE}/pick`);
    await shot(page, '80-pick-queue', { fullPage: true });
    const list1 = lookup('id', 'pick_lists', 'number', 'PK-2026-00001');
    await page.goto(`${BASE}/pick/lists/${list1}`);
    await shot(page, '81-pick-line');
    const scan = page.locator('input[placeholder^="Scan"]');
    await scan.fill('B999999');
    await scan.press('Enter');
    await shot(page, '82-pick-wrong-scan');
    const binCode = await page.locator('.font-mono.text-4xl').textContent();
    const barcode = lookup('value', 'locations', 'code', binCode.trim(), 'barcode');
    await scan.fill(barcode);
    await scan.press('Enter');
    await shot(page, '83-pick-bin-ok');
    await page.click('text=Enter quantity');
    await page.fill('input[inputmode=numeric]', '1');
    await shot(page, '84-pick-enter-quantity');
    await page.click('button:has-text("Back")');
    await page.click('text=Short / not found');
    await page.fill('input[inputmode=numeric]', '0');
    await shot(page, '85-pick-short');
    await page.context().close();

    page = await newPage(browser, true);
    await login(page, 'casey.picker@example.com');
    const list2 = lookup('id', 'pick_lists', 'number', 'PK-2026-00002');
    await page.goto(`${BASE}/pick/lists/${list2}/pack`);
    await shot(page, '86-pack', { fullPage: true });
    const run2 = lookup('value', 'pick_lists', 'number', 'PK-2026-00002', 'delivery_run_id');
    await page.goto(`${BASE}/pick/runs/${run2}`);
    await shot(page, '87-run-manifest', { fullPage: true });
    await page.context().close();

    await browser.close();
    console.log('Done');
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
