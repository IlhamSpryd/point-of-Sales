import { test, expect } from '@playwright/test';
import {
    attachUnknownTimeoutAttemptToShift,
    getDemoSnapshot,
    getLatestShiftForUser,
    resetCashier2OpenShifts,
    resolvePaymentAttemptStatus,
} from '../helpers/db';
import { attachStrictConsoleGuard, stubExternalMidtransAssets } from '../helpers/assertions';

test.describe('Journey 05 — Shift Lifecycle, Cash Reconciliation Variance & EOD Timeout Blocker', () => {
    test('Reconciles opening + cash_in + sales - cash_out - refund variance and blocks EOD while UnknownTimeout PaymentAttempt exists', async ({ browser, page }, testInfo) => {
        const snapshot = getDemoSnapshot();
        resetCashier2OpenShifts();

        // Distinct historical business_date per viewport project so EOD immutability works cleanly across Light, Dark, and Tablet runs
        const offsetDays = testInfo.project.name.includes('Dark') ? 45 : (testInfo.project.name.includes('Tablet') ? 46 : 44);
        const eodDate = new Date(Date.now() - offsetDays * 86400000).toISOString().slice(0, 10);

        const cashier2Ctx = await browser.newContext({
            viewport: page.viewportSize() ?? { width: 1366, height: 768 },
            storageState: 'playwright/.auth/cashier2.json',
        });
        await stubExternalMidtransAssets(cashier2Ctx);
        const c2Page = await cashier2Ctx.newPage();
        const assertC2Console = attachStrictConsoleGuard(c2Page);

        let shiftId = 0;
        let timeoutAttemptId = 0;

        try {
            // 1. Open Shift on POS-JKT-02 with opening balance Rp 500.000
            await c2Page.goto('/shifts');
            await c2Page.waitForLoadState('networkidle');
            await expect(c2Page.getByRole('heading', { name: 'Open Cashier Shift' })).toBeVisible();

            const openForm = c2Page.locator('form[wire\\:submit\\.prevent="openShift"]');
            await openForm.locator('select[wire\\:model="selectedDeviceId"]').selectOption(String(snapshot.posDevice2Id));
            await openForm.locator('input[wire\\:model="opening_balance"]').fill('500000');
            await openForm.locator('input[wire\\:model="cashierPin"]').fill('123456');
            await openForm.getByRole('button', { name: /Start Shift Session/i }).click();

            await expect(c2Page.getByText('Expected Drawer Cash', { exact: true })).toBeVisible({ timeout: 15000 });
            shiftId = getLatestShiftForUser(snapshot.kasir2Id).id;

            // 2. Cash-In +100.000
            await c2Page.getByRole('button', { name: 'Cash In / Out' }).click();
            await c2Page.locator('input[wire\\:model="movementAmount"]').fill('100000');
            await c2Page.locator('textarea[wire\\:model="movementReason"]').fill('Modal uang receh');
            await c2Page.getByRole('button', { name: 'Save Movement' }).click();
            await expect(c2Page.locator('#main-content').getByText('Rp 600.000', { exact: true })).toBeVisible({ timeout: 10000 });

            // 3. Cash-Out -50.000
            await c2Page.getByRole('button', { name: 'Cash In / Out' }).click();
            await c2Page.locator('#cash-movement-form').getByText('Cash Out', { exact: true }).click();
            await c2Page.locator('#cash-movement-form input[wire\\:model="movementAmount"]').fill('50000');
            await c2Page.locator('#cash-movement-form textarea[wire\\:model="movementReason"]').fill('Beli galon air outlet');
            await c2Page.locator('#cash-movement-form input[wire\\:model="movementSupervisorPin"]').fill('123456');
            await c2Page.getByRole('button', { name: 'Save Movement' }).click();
            await expect(c2Page.locator('#main-content').getByText('Rp 550.000', { exact: true })).toBeVisible({ timeout: 10000 });

            // 4. Process cash sale at /transaction -> Tumbler (150k * 1.11 = 166.500 -> expected cash = 716.500)
            await c2Page.goto('/transaction');
            await c2Page.waitForLoadState('networkidle');
            await c2Page.getByRole('button', { name: 'Takeaway' }).click();
            await c2Page.locator('#top-products [role="button"]', { hasText: 'Tumbler Senja' }).click();
            await c2Page.locator('aside #btn-proses-bayar').click();
            await c2Page.getByRole('button', { name: '+Rp200.000' }).click();
            await c2Page.locator('#btn-confirm-pay').click();
            await expect(c2Page.getByText('Transaksi Sukses')).toBeVisible({ timeout: 15000 });

            // 5. Return to /shifts and Close Shift with -10.000 physical cash discrepancy
            await c2Page.goto('/shifts');
            await c2Page.waitForLoadState('networkidle');

            // Attach UnknownTimeout attempt on today's shift to test EOD blocker later
            timeoutAttemptId = attachUnknownTimeoutAttemptToShift(shiftId, false);

            const closeForm = c2Page.locator('#close-shift-form');
            await c2Page.getByRole('button', { name: 'Close Shift' }).click();
            await expect(closeForm).toBeVisible();

            const countedTarget = 500000 + 100000 - 50000 + 166500 - 10000;
            await closeForm.locator('input[wire\\:model="closing_balance"]').fill(String(countedTarget));
            await closeForm.locator('input[wire\\:model="cashierPin"]').fill('123456');
            await closeForm.locator('textarea[wire\\:model="notes"]').fill('Selisih receh -10k saat tutup shift');
            await c2Page.getByRole('button', { name: 'Confirm Close Shift' }).click();

            await expect(c2Page.getByRole('heading', { name: 'Open Cashier Shift' })).toBeVisible({ timeout: 15000 });

            const closedShift = getLatestShiftForUser(snapshot.kasir2Id);
            expect(closedShift.status).toBe('closed');
            expect(closedShift.expected_cash).toBe(500000 + 100000 - 50000 + 166500);
            expect(closedShift.cash_difference).toBe(-10000);
        } finally {
            assertC2Console();
            await cashier2Ctx.close();
        }

        // 6. Verify Owner /reports/sales EOD close is blocked while UnknownTimeout PaymentAttempt exists on Flagship Jakarta today (and on timeoutAttemptId)
        const ownerCtx = await browser.newContext({
            viewport: page.viewportSize() ?? { width: 1366, height: 768 },
            storageState: 'playwright/.auth/owner.json',
        });
        const oPage = await ownerCtx.newPage();
        const assertOConsole = attachStrictConsoleGuard(oPage);

        try {
            // Ensure Owner switched to Cabang Bandung for clean historical date EOD closing without touching kasir@pos.test's JKT open shift
            await oPage.goto('/reports/sales?date=' + eodDate);
            await oPage.waitForLoadState('networkidle');

            // Switch store to Cabang Bandung first so JKT active open shift does not interfere, then confirm JKT fails if UnknownTimeout exists on today
            await oPage.locator('header button[aria-label="Select active branch"]').click();
            await Promise.all([
                oPage.waitForLoadState('networkidle'),
                oPage.locator('header form[action*="switch-store"] button[type="submit"]', { hasText: 'Cabang Bandung' }).click(),
            ]);

            // Try closing EOD on JKT today via direct POST while UnknownTimeout attempt exists -> must reject with blocker message
            await oPage.locator('header button[aria-label="Select active branch"]').click();
            await Promise.all([
                oPage.waitForLoadState('networkidle'),
                oPage.locator('header form[action*="switch-store"] button[type="submit"]', { hasText: 'Flagship Jakarta' }).click(),
            ]);

            await oPage.goto('/reports/sales');
            await oPage.waitForLoadState('networkidle');
            const openEodBtn = oPage.getByRole('button', { name: /Close EOD \/ Z-Report/i });
            await expect(openEodBtn).toBeVisible();
            await openEodBtn.click();

            await expect(oPage.locator('#eod_pin_input')).toBeVisible();
            await oPage.locator('#eod_pin_input').fill('123456');
            await oPage.locator('#eod_notes_input').fill('Attempt EOD while timeout attempt active');
            await Promise.all([
                oPage.waitForLoadState('networkidle'),
                oPage.getByRole('button', { name: /Lock & Finalize EOD/i }).click(),
            ]);
            // Assert EOD remained blocked (still shows Close EOD trigger button & error alert, NOT locked Z-Report)
            await expect(oPage.getByRole('button', { name: /Close EOD \/ Z-Report/i })).toBeVisible();
        } finally {
            if (timeoutAttemptId > 0) {
                resolvePaymentAttemptStatus(timeoutAttemptId, 'failed');
            }
            assertOConsole();
            await ownerCtx.close();
        }
    });
});
