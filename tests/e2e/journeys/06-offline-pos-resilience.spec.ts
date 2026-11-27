import { test, expect } from '@playwright/test';
import { countOrdersByOfflineUuid } from '../helpers/db';
import { attachStrictConsoleGuard, stubExternalMidtransAssets } from '../helpers/assertions';

test.describe('Journey 06 — Offline POS Queue, Synchronous Logout Guard & Idempotent Auto-Sync', () => {
    test('Enqueues cash sale offline with badge=1, blocks logout synchronously on dismiss, auto-syncs once on reconnect, and survives duplicate replay', async ({ browser, page }) => {
        // Fresh isolated login session so testing logout behavior never invalidates shared storageState
        const ctx = await browser.newContext({
            viewport: page.viewportSize() ?? { width: 1366, height: 768 },
        });
        await stubExternalMidtransAssets(ctx);
        const offlinePage = await ctx.newPage();
        const assertCleanConsole = attachStrictConsoleGuard(offlinePage, { allowOfflineNetworkErrors: true });

        try {
            await offlinePage.goto('/login');
            await offlinePage.fill('#email', 'kasir@pos.test');
            await offlinePage.fill('#password', process.env.E2E_PASSWORD || 'password123');
            await offlinePage.click('button[type="submit"]');
            await offlinePage.waitForURL(/(transaction|dashboard)/i, { timeout: 20000 });
            if (!offlinePage.url().includes('/transaction')) {
                await offlinePage.goto('/transaction');
            }
            await offlinePage.waitForLoadState('networkidle');
            await expect(offlinePage.locator('#livewire-pos-root')).toBeVisible();

            // Clear any leftover offline queue items from earlier runs
            await offlinePage.evaluate(async () => {
                if (!window.ApeironOfflinePos) return;
                const pending = await window.ApeironOfflinePos.getPendingOrders();
                for (const item of pending) {
                    await window.ApeironOfflinePos.removeOrder(item.offline_client_uuid);
                }
            });

            // 1. Add Air Mineral (no modifier modal needed) + select Takeaway
            await offlinePage.getByRole('button', { name: 'Takeaway' }).click();
            const airMineral = offlinePage.locator('#top-products [role="button"]', { hasText: 'Air Mineral' });
            await expect(airMineral).toBeVisible();
            await airMineral.click();
            const cartAside = offlinePage.locator('aside');
            await expect(cartAside.locator('[data-cart-index="0"]')).toBeVisible();

            // 2. Toggle offline mode cleanly
            await ctx.setOffline(true);
            await offlinePage.evaluate(() => window.dispatchEvent(new Event('offline')));
            await expect(offlinePage.getByTestId('offline-status-badge')).toBeVisible();

            // 3. Complete Cash sale offline
            await cartAside.locator('#btn-proses-bayar').click();
            await expect(offlinePage.getByRole('heading', { name: 'Complete Payment (Cashier)' })).toBeVisible();
            await offlinePage.locator('#btn-confirm-pay').click();

            // Expect pending queue badge count === 1 & IndexedDB holds 1 record
            await expect(offlinePage.getByTestId('offline-queue-badge')).toBeVisible();
            await expect(offlinePage.getByTestId('offline-queue-count')).toHaveText('1');

            const pendingPreSync = await offlinePage.evaluate(async () => {
                return await (window as unknown as { ApeironOfflinePos: { getPendingOrders: () => Promise<Array<{ offline_client_uuid: string; payload: unknown }>> } }).ApeironOfflinePos.getPendingOrders();
            });
            expect(pendingPreSync).toHaveLength(1);
            const queuedOrder = pendingPreSync[0];

            // 4. Attempt Logout while offline queue is pending -> synchronous confirm() guard must cancel logout when dismissed
            await offlinePage.locator('button[aria-label="User account menu"]').click();
            let dialogMessage = '';
            offlinePage.once('dialog', async (dialog) => {
                dialogMessage = dialog.message();
                await dialog.dismiss();
            });
            const logoutBtn = offlinePage.locator('form[action*="logout"] button[type="submit"]');
            await expect(logoutBtn).toBeVisible();
            await logoutBtn.click();

            expect(dialogMessage).toContain('transaksi offline');
            expect(offlinePage.url()).toContain('/transaction');

            // 5. Reconnect network -> auto-sync flushes IndexedDB and badge clears to 0
            await ctx.setOffline(false);
            await offlinePage.evaluate(() => window.dispatchEvent(new Event('online')));

            await expect(offlinePage.getByTestId('offline-status-badge')).toBeHidden({ timeout: 15000 });
            await expect(offlinePage.getByTestId('offline-queue-badge')).toBeHidden({ timeout: 15000 });

            const pendingPostSync = await offlinePage.evaluate(async () => {
                return await (window as unknown as { ApeironOfflinePos: { getPendingCount: () => Promise<number> } }).ApeironOfflinePos.getPendingCount();
            });
            expect(pendingPostSync).toBe(0);

            // 6. Duplicate UUID replay against /pos/offline/sync creates ZERO duplicates
            const csrfToken = await offlinePage.locator('meta[name="csrf-token"]').getAttribute('content');
            const replayRes = await offlinePage.request.post('/pos/offline/sync', {
                headers: { 'X-CSRF-TOKEN': csrfToken || '' },
                data: {
                    orders: [queuedOrder.payload],
                },
            });
            expect(replayRes.status()).toBe(200);
            const replayJson = await replayRes.json();
            expect(replayJson.data.results[0].status).toBe('already_synced');

            expect(countOrdersByOfflineUuid(queuedOrder.offline_client_uuid)).toBe(1);
        } finally {
            assertCleanConsole();
            await ctx.close();
        }
    });
});

