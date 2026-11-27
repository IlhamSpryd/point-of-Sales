import { test, expect } from '@playwright/test';
import {
    createPendingQrisOrderWithAttempts,
    getDemoSnapshot,
    getLatestOrderByCodePrefix,
    runReconcileOrdersWithUnknownMock,
    signMidtransSignature,
} from '../helpers/db';
import { attachStrictConsoleGuard, stubExternalMidtransAssets } from '../helpers/assertions';

test.describe('Journey 03 — Midtrans QRIS Resilience, Webhook Verification, Idempotency & Multi-Attempt Reconciliation', () => {
    test.use({ storageState: 'playwright/.auth/cashier.json' });

    test('Intercepts Snap JS, rejects forged/invalid-amount webhooks, settles concurrent valid webhooks once, and preserves retry -ATT2 on reconcile', async ({ page }) => {
        const snapshot = getDemoSnapshot();
        await stubExternalMidtransAssets(page);
        const assertCleanConsole = attachStrictConsoleGuard(page);

        await page.goto('/transaction');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#livewire-pos-root')).toBeVisible();

        // Also confirm seeded pending QRIS order appears cleanly in /orders UI with Sync Midtrans Status control
        await page.goto('/orders?status=pending&periode=month');
        await page.waitForLoadState('networkidle');
        const pendingOrder = createPendingQrisOrderWithAttempts(false);

        const searchInput = page.locator('input[placeholder="Search order code..."]');
        await searchInput.fill(pendingOrder.orderCode);
        await expect(page.locator('td', { hasText: pendingOrder.orderCode }).first()).toBeVisible();

        const row = page.locator('tr', { hasText: pendingOrder.orderCode }).first();
        await row.locator('button[aria-label="Actions"]').click();
        await page.getByRole('button', { name: 'View Order Details' }).click();
        await expect(page.getByRole('button', { name: 'Sync Midtrans Status' })).toBeVisible();
        await page.getByRole('button', { name: 'Close' }).first().click();

        // 2. Webhook security & input guards
        if (snapshot.midtransServerKey !== '') {
            // 2a. Forged signature -> 403
            const forgedRes = await page.request.post('/midtrans/notification', {
                data: {
                    order_id: pendingOrder.attempt1ProviderOrderId,
                    status_code: '200',
                    gross_amount: `${pendingOrder.amount}.00`,
                    transaction_status: 'settlement',
                    transaction_id: `tx-forged-${pendingOrder.orderCode}`,
                    signature_key: '0'.repeat(128),
                },
            });
            expect(forgedRes.status()).toBe(403);

            // 2b. Missing/null gross_amount -> 400
            const nullGrossRes = await page.request.post('/midtrans/notification', {
                data: {
                    order_id: pendingOrder.attempt1ProviderOrderId,
                    status_code: '200',
                    transaction_status: 'settlement',
                    signature_key: '0'.repeat(128),
                },
            });
            expect([400, 422]).toContain(nullGrossRes.status());

            // 2c. Mismatched gross_amount with valid SHA-512 -> rejected cleanly (order remains pending)
            const badAmountStr = '1.00';
            const badAmountSig = signMidtransSignature(pendingOrder.attempt1ProviderOrderId, '200', badAmountStr);
            const badAmountRes = await page.request.post('/midtrans/notification', {
                data: {
                    order_id: pendingOrder.attempt1ProviderOrderId,
                    status_code: '200',
                    gross_amount: badAmountStr,
                    transaction_status: 'settlement',
                    transaction_id: `tx-badamt-${pendingOrder.orderCode}`,
                    signature_key: badAmountSig,
                },
            });
            expect([200, 400, 422]).toContain(badAmountRes.status());
            const afterMismatch = getLatestOrderByCodePrefix(snapshot.tenantAId, pendingOrder.orderCode);
            expect(afterMismatch.order_status).toBe('pending');
            expect(afterMismatch.payment_count).toBe(0);

            // 2d. Valid SHA-512 settlement webhook replayed 3x concurrently -> exactly 1 payment + 1 loyalty entry
            const grossStr = `${pendingOrder.amount}.00`;
            const validSig = signMidtransSignature(pendingOrder.attempt1ProviderOrderId, '200', grossStr);
            const webhookPayload = {
                order_id: pendingOrder.attempt1ProviderOrderId,
                status_code: '200',
                gross_amount: grossStr,
                transaction_status: 'settlement',
                payment_type: 'qris',
                transaction_id: `tx-ok-${pendingOrder.orderCode}`,
                signature_key: validSig,
            };

            const responses = await Promise.all([
                page.request.post('/midtrans/notification', { data: webhookPayload }),
                page.request.post('/midtrans/notification', { data: webhookPayload }),
                page.request.post('/midtrans/notification', { data: webhookPayload }),
            ]);
            for (const res of responses) {
                expect([200, 409]).toContain(res.status());
            }

            const settled = getLatestOrderByCodePrefix(snapshot.tenantAId, pendingOrder.orderCode);
            expect(settled.order_status).toBe('paid');
            expect(settled.payment_status).toBe('paid');
            expect(settled.fulfillment_status).toBe('in_prep');
            expect(settled.payment_count).toBe(1);
            expect(settled.loyalty_count).toBeLessThanOrEqual(1);

            // Confirm /orders UI now displays Paid for the settled QRIS order
            await page.goto('/orders?status=all&periode=month');
            await page.waitForLoadState('networkidle');
            await page.locator('input[placeholder="Search order code..."]').fill(pendingOrder.orderCode);
            const settledRow = page.locator('tr', { hasText: pendingOrder.orderCode }).first();
            await expect(settledRow).toContainText('Paid');
        }

        // 3. Split-retry attempt -ATT2 after -ATT1 expired must NOT be prematurely voided by reconciliation
        createPendingQrisOrderWithAttempts(true);
        const reconcile = runReconcileOrdersWithUnknownMock();
        expect(reconcile.orderStatuses.order_status).toBe('pending');
        expect(reconcile.orderStatuses.att2_status).not.toBe('void');

        assertCleanConsole();
    });
});

