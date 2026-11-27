import { test, expect } from '@playwright/test';
import { getDemoSnapshot } from '../helpers/db';
import { attachStrictConsoleGuard, stubExternalMidtransAssets } from '../helpers/assertions';

test.describe('Journey 01 — Auth, Tenancy Isolation & Multi-Branch Switching', () => {
    test('Owner switches Flagship Jakarta <-> Cabang Bandung with isolated stock balances, blocks Tenant B IDOR, and denies custom FloorLead on /subscription', async ({ page, browser }) => {
        const snapshot = getDemoSnapshot();
        const assertCleanConsole = attachStrictConsoleGuard(page);

        // 1. Owner starts at /dashboard using storageState
        const ownerCtx = await browser.newContext({
            viewport: page.viewportSize() ?? { width: 1366, height: 768 },
            storageState: 'playwright/.auth/owner.json',
        });
        await stubExternalMidtransAssets(ownerCtx);
        const ownerPage = await ownerCtx.newPage();
        const assertOwnerConsole = attachStrictConsoleGuard(ownerPage);

        try {
            await ownerPage.goto('/dashboard');
            await ownerPage.waitForLoadState('networkidle');
            await expect(ownerPage.locator('header')).toBeVisible();

            // 2. Switch branch -> Cabang Bandung via header Branch Switcher
            const switcherBtn = ownerPage.locator('header button[aria-label="Select active branch"]');
            await expect(switcherBtn).toBeVisible();
            await switcherBtn.click();

            const bdgButton = ownerPage.locator('header form[action*="switch-store"] button[type="submit"]', { hasText: 'Cabang Bandung' });
            await expect(bdgButton).toBeVisible();
            await Promise.all([
                ownerPage.waitForLoadState('networkidle'),
                bdgButton.click(),
            ]);
            await expect(switcherBtn).toContainText('Cabang Bandung');

            // Verify POS grid reflects Cabang Bandung isolated stock for RT-DRIPBOX (18 in BDG vs 43 in JKT)
            await ownerPage.goto('/transaction');
            await ownerPage.waitForLoadState('networkidle');
            const dripCardBdg = ownerPage.locator('#top-products [role="button"]', { hasText: 'Drip Bag Box' });
            await expect(dripCardBdg).toBeVisible();
            await expect(dripCardBdg).toContainText('Stock: 18');

            // Switch branch -> Flagship Jakarta and confirm distinct JKT stock (43)
            await switcherBtn.click();
            const jktButton = ownerPage.locator('header form[action*="switch-store"] button[type="submit"]', { hasText: 'Flagship Jakarta' });
            await Promise.all([
                ownerPage.waitForLoadState('networkidle'),
                jktButton.click(),
            ]);
            await expect(switcherBtn).toContainText('Flagship Jakarta');

            await ownerPage.goto('/transaction');
            await ownerPage.waitForLoadState('networkidle');
            const dripCardJkt = ownerPage.locator('#top-products [role="button"]', { hasText: 'Drip Bag Box' });
            await expect(dripCardJkt).toContainText('Stock: 43');

            // 3. Direct URL IDOR probes against Tenant B ("Warung Mba Rina")
            const orderProbe = await ownerPage.request.get(`/orders/${snapshot.tenantBOrderCode}`);
            expect([403, 404]).toContain(orderProbe.status());
            const orderProbeBody = await orderProbe.text();
            expect(orderProbeBody).not.toContain('Warung Mba Rina');
            expect(orderProbeBody).not.toContain('Beras 5kg');

            const apiOrderProbe = await ownerPage.request.get(`/api/v1/orders/${snapshot.tenantBOrderId}`);
            expect([401, 403, 404]).toContain(apiOrderProbe.status());
            const apiBody = await apiOrderProbe.text();
            expect(apiBody).not.toContain('WMR-DEMO-0001');
            expect(apiBody).not.toContain('Warung Mba Rina');

            // Livewire state replay probe against Tenant B AccountingPeriodManager
            await ownerPage.goto('/finance/accounting-periods');
            await ownerPage.waitForLoadState('networkidle');
            const wireId = await ownerPage.locator('[wire\\:id]').first().getAttribute('wire:id');
            const wireSnapshot = await ownerPage.locator('[wire\\:id]').first().getAttribute('wire:snapshot');
            const csrfToken = await ownerPage.locator('meta[name="csrf-token"]').getAttribute('content');
            const updateUri = await ownerPage.locator('script[data-update-uri]').getAttribute('data-update-uri');
            expect(updateUri).toBeTruthy();

            if (wireId && wireSnapshot && csrfToken && updateUri) {
                const replayRes = await ownerPage.request.post(updateUri, {
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Livewire': '1',
                        'Content-Type': 'application/json',
                    },
                    data: {
                        components: [
                            {
                                snapshot: wireSnapshot,
                                updates: {},
                                calls: [
                                    { path: '', method: 'hardClose', params: [snapshot.tenantBPeriodId] },
                                ],
                            },
                        ],
                    },
                });
                expect([403, 404, 422, 500]).toContain(replayRes.status());
                const replayText = await replayRes.text();
                expect(replayText).not.toContain('Warung Mba Rina');
            }
        } finally {
            assertOwnerConsole();
            await ownerCtx.close();
        }

        // 4. Custom non-owner role (FloorLead) denied access to /subscription (SoD)
        const staffCtx = await browser.newContext({
            viewport: page.viewportSize() ?? { width: 1366, height: 768 },
            storageState: 'playwright/.auth/staff.json',
        });
        const staffPage = await staffCtx.newPage();
        try {
            const subRes = await staffPage.goto('/subscription');
            expect(subRes?.status()).toBe(403);
        } finally {
            await staffCtx.close();
        }

        assertCleanConsole();
    });
});

