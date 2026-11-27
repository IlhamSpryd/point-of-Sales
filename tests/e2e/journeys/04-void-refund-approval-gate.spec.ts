import { test, expect } from '@playwright/test';
import {
    createPaidCashOrderForReturnVoid,
    getDemoSnapshot,
    getLatestOrderByCodePrefix,
    getOutboxReturnEvents,
    getStockSnapshot,
} from '../helpers/db';
import { attachStrictConsoleGuard } from '../helpers/assertions';

test.describe('Journey 04 — Partial Return, Void PIN Authorization & Financial Outbox/Restock Invariants', () => {
    test('Blocks Cashier void, enforces return refund allocation & Supervisor PIN gate, restores +1 stock, and emits outbox event', async ({ browser, page }) => {
        const snapshot = getDemoSnapshot();
        const returnFixture = createPaidCashOrderForReturnVoid(2, 'RT-TUMBLER');
        const voidFixture = createPaidCashOrderForReturnVoid(1, 'RT-BEANS-250');

        // 1. Cashier cannot void an order
        const cashierCtx = await browser.newContext({
            viewport: page.viewportSize() ?? { width: 1366, height: 768 },
            storageState: 'playwright/.auth/cashier.json',
        });
        const cashierPage = await cashierCtx.newPage();
        const assertCashierConsole = attachStrictConsoleGuard(cashierPage);
        try {
            await cashierPage.goto('/orders');
            await cashierPage.waitForLoadState('networkidle');
            const cashierCsrf = await cashierPage.locator('meta[name="csrf-token"]').getAttribute('content');
            const voidForbidden = await cashierPage.request.post(`/orders/${returnFixture.orderCode}/void`, {
                headers: {
                    'X-CSRF-TOKEN': cashierCsrf || '',
                    'Accept': 'application/json',
                },
                data: { reason: 'Unauthorized attempt by cashier' },
            });
            expect(voidForbidden.status()).toBe(403);
        } finally {
            assertCashierConsole();
            await cashierCtx.close();
        }

        // 2. Supervisor executes Partial Return (Qty 1 of 2, Restock) & Void PIN gate on /orders
        const supCtx = await browser.newContext({
            viewport: page.viewportSize() ?? { width: 1366, height: 768 },
            storageState: 'playwright/.auth/supervisor.json',
        });
        const supPage = await supCtx.newPage();
        const assertSupConsole = attachStrictConsoleGuard(supPage);

        try {
            await supPage.goto('/orders');
            await supPage.waitForLoadState('networkidle');

            const searchInput = supPage.locator('input[placeholder="Search order code..."]');
            await searchInput.fill(returnFixture.orderCode);
            const orderRow = supPage.locator('tr', { hasText: returnFixture.orderCode }).first();
            await expect(orderRow).toBeVisible();

            await orderRow.locator('button[aria-label="Actions"]').click();
            await supPage.getByRole('button', { name: 'View Order Details' }).click();
            await supPage.getByRole('button', { name: /Return & Refund/i }).click();

            const returnTitle = supPage.locator('#return-modal-title');
            await expect(returnTitle).toBeVisible();

            // Item starts pre-selected when remaining > 0; wait deterministically for Livewire debounced updates
            const isLivewireUpdate = (r: { url(): string; status(): number }): boolean =>
                r.url().includes('/livewire') && r.url().endsWith('/update') && r.status() === 200;

            const qtyInput = supPage.locator('input[wire\\:model\\.live\\.debounce\\.300ms*="returnItems"]').first();
            await expect(qtyInput).toBeVisible();
            await Promise.all([
                supPage.waitForResponse(isLivewireUpdate),
                qtyInput.fill('1'),
            ]);

            // Attempt over-allocation > payment max (Rp 383.000 > Rp 333.000) -> rejected cleanly
            const allocInput = supPage.locator('input[wire\\:model\\.live\\.debounce\\.300ms*="refundAllocations"]').first();
            await Promise.all([
                supPage.waitForResponse(isLivewireUpdate),
                allocInput.fill(String(returnFixture.orderAmount + 50000)),
            ]);
            await supPage.locator('input[wire\\:model="returnReason"]').fill('Damaged item return test');
            await supPage.locator('input[wire\\:model="returnSupervisorPin"]').fill('135790');
            await Promise.all([
                supPage.waitForResponse(isLivewireUpdate),
                supPage.getByRole('button', { name: /Process Return & Refund/i }).click(),
            ]);

            await expect(returnTitle).toBeVisible();
            const unchangedAfterBadAlloc = getLatestOrderByCodePrefix(snapshot.tenantAId, returnFixture.orderCode);
            expect(unchangedAfterBadAlloc.return_count).toBe(0);

            // Fix allocation to exact half (unitPrice + proportional tax <= item total) and process valid Partial Return
            const singleItemRefund = Math.floor(returnFixture.orderAmount / 2);
            await Promise.all([
                supPage.waitForResponse(isLivewireUpdate),
                allocInput.fill(String(singleItemRefund)),
            ]);
            await supPage.getByRole('button', { name: /Process Return & Refund/i }).click();

            await expect(returnTitle).toBeHidden({ timeout: 15000 });

            // Assert stock balance restored by exact +1, PaymentRefund + CashDrawerMovement + Outbox created
            const stockAfterReturn = getStockSnapshot(snapshot.tenantAId, snapshot.jktStoreId, returnFixture.productId, snapshot.coffeeIngredientId);
            expect(stockAfterReturn.productStock).toBe(returnFixture.stockBefore + 1);

            const afterReturn = getLatestOrderByCodePrefix(snapshot.tenantAId, returnFixture.orderCode);
            expect(afterReturn.return_count).toBe(1);
            expect(afterReturn.refund_count).toBe(1);
            expect(afterReturn.drawer_refund_count).toBeGreaterThanOrEqual(1);
            expect(getOutboxReturnEvents(returnFixture.orderId)).toBe(1);

            // 3. Close detail modal for first order, then test Void PIN Gate on second order
            await supPage.locator('#order-detail-modal').getByRole('button', { name: 'Close', exact: true }).click();
            await expect(supPage.locator('#order-detail-title')).toBeHidden();

            await searchInput.fill(voidFixture.orderCode);
            const voidRow = supPage.locator('tr', { hasText: voidFixture.orderCode }).first();
            await expect(voidRow).toBeVisible();
            await voidRow.locator('button[aria-label="Actions"]').click();
            await supPage.getByRole('button', { name: 'View Order Details' }).click();
            await supPage.getByRole('button', { name: 'Void Order' }).click();

            await expect(supPage.locator('#void-modal-title')).toBeVisible();
            await supPage.locator('#void-reason-input').fill('Customer cancelled before dispatch');
            await supPage.locator('#void-pin-input').fill('000000');
            await supPage.getByRole('button', { name: 'Confirm Void' }).click();
            await expect(supPage.getByText('Invalid supervisor PIN.')).toBeVisible();

            await supPage.locator('#void-pin-input').fill('135790');
            await supPage.getByRole('button', { name: 'Confirm Void' }).click();
            await expect(supPage.locator('#void-modal-title')).toBeHidden({ timeout: 15000 });

            const voidedOrder = getLatestOrderByCodePrefix(snapshot.tenantAId, voidFixture.orderCode);
            expect(voidedOrder.order_status).toBe('void');
        } finally {
            assertSupConsole();
            await supCtx.close();
        }
    });
});

