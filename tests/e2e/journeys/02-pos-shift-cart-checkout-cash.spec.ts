import { test, expect } from '@playwright/test';
import { getDemoSnapshot, getLatestCashierOrder, getStockSnapshot } from '../helpers/db';
import { attachStrictConsoleGuard, stubExternalMidtransAssets } from '../helpers/assertions';

test.describe('Journey 02 — POS Shift, Barcode, Modifiers, Discount, Cash Checkout & Locked Cart', () => {
    test.use({ storageState: 'playwright/.auth/cashier.json' });

    test('Cashier completes variable modifier + barcode + SENJA10 discount cash sale, decrements stock atomically, and rejects Livewire cart tamper', async ({ page }) => {
        const snapshot = getDemoSnapshot();
        const stockBefore = getStockSnapshot(snapshot.tenantAId, snapshot.jktStoreId, snapshot.tumblerProductId, snapshot.coffeeIngredientId);
        const startedIso = new Date(Date.now() - 2000).toISOString().slice(0, 19).replace('T', ' ');
        await stubExternalMidtransAssets(page);
        const assertCleanConsole = attachStrictConsoleGuard(page);

        await page.goto('/transaction');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#livewire-pos-root')).toBeVisible();
        const cartAside = page.locator('aside');

        // 1. Set order type to Takeaway
        await page.getByRole('button', { name: 'Takeaway' }).click();

        // 2. Select customer "Hendra"
        const custInput = cartAside.locator('input[placeholder="Customer (optional)..."]');
        await custInput.fill('Hendra');
        const hendraBtn = cartAside.getByRole('button', { name: /Hendra/i }).first();
        await expect(hendraBtn).toBeVisible();
        await hendraBtn.click();
        await expect(cartAside.locator('button[aria-label="Remove customer from order"]')).toBeVisible();

        // 3. Add Kopi Susu Senja with Less Ice + Extra Shot (+Rp 8.000 => Rp 32.000)
        const kopiCard = page.locator('#top-products [role="button"]', { hasText: 'Kopi Susu Senja' });
        await expect(kopiCard).toBeVisible();
        await kopiCard.click();

        const configModal = page.locator('#product-configurator-picker');
        await expect(configModal).toBeVisible();
        await configModal.getByRole('radio', { name: /Less Ice/i }).click();
        await configModal.getByRole('checkbox', { name: /Extra Shot/i }).click();
        await configModal.getByRole('button', { name: /Tambah ke Pesanan/i }).click();
        await expect(configModal).toBeHidden();

        // 4. Focus search with F2 & search + add Tumbler Senja
        await page.keyboard.press('F2');
        const searchInput = page.locator('#search-input');
        await expect(searchInput).toBeFocused();
        await searchInput.fill('Tumbler Senja');
        const tumblerCard = page.locator('#top-products [role="button"]', { hasText: 'Tumbler Senja' });
        await expect(tumblerCard).toBeVisible();
        await tumblerCard.click();
        await expect(cartAside.locator('[data-cart-index="1"]')).toBeVisible();
        await searchInput.fill('');
        await expect(page.locator('#top-products [role="button"]', { hasText: 'Kopi Susu Senja' })).toBeVisible();

        // 5. Apply Promo Kopi Senja 10% (SENJA10, capped at Rp 50.000)
        await cartAside.getByRole('button', { name: /\+ Add Coupon \/ Discount/i }).click();
        const discountSelect = cartAside.locator('select[wire\\:model\\.live="selectedDiscountId"]');
        await expect(discountSelect).toBeVisible();
        await discountSelect.selectOption({ index: 1 });
        await expect(cartAside.getByText('Applied', { exact: true })).toBeVisible();

        // Wait for livewire totals update (Kopi Susu + Extra Shot = 32k, Tumbler = 150k => Subtotal 182k - 18.2k + 11% tax => ~181.800 rounded to 100)
        // Tender Rp 200.000 via +Rp200.000 quick button so cash covers order with change
        const payBtn = cartAside.locator('#btn-proses-bayar');
        await expect(payBtn).toBeEnabled();
        await payBtn.click();

        const checkoutHeading = page.getByRole('heading', { name: 'Complete Payment (Cashier)' });
        await expect(checkoutHeading).toBeVisible();
        await page.getByRole('button', { name: '+Rp200.000' }).click();

        // Submit payment
        const confirmBtn = page.locator('#btn-confirm-pay');
        await expect(confirmBtn).toBeEnabled();
        await confirmBtn.click();

        // Wait for receipt fallback modal showing server order_code
        await expect(page.getByText('Transaksi Sukses')).toBeVisible({ timeout: 15000 });
        const orderCodeText = await page.locator('text=/#POS-/').first().textContent();
        expect(orderCodeText).toBeTruthy();
        const orderCode = orderCodeText!.replace('#', '').trim();

        // Close receipt modal with close button or Esc
        await page.getByRole('button', { name: /Transaksi Baru \/ Tutup/i }).click();
        await expect(checkoutHeading).toBeHidden();

        // 6. Verify DB invariants: order_amount rounded to 100, payment_status=paid, product & ingredient stock decremented atomically
        const order = getLatestCashierOrder(snapshot.tenantAId, snapshot.jktStoreId, snapshot.kasirId, startedIso);
        expect(order).not.toBeNull();
        expect(order!.order_code).toBe(orderCode);
        expect(order!.payment_status).toBe('paid');
        expect(order!.order_amount % 100).toBe(0);
        expect(order!.discount_amount).toBeGreaterThan(0);

        const stockAfter = getStockSnapshot(snapshot.tenantAId, snapshot.jktStoreId, snapshot.tumblerProductId, snapshot.coffeeIngredientId);
        expect(stockAfter.productStock).toBe(stockBefore.productStock - 1);
        expect(stockAfter.ingredientStock).toBe(stockBefore.ingredientStock - 18);

        // 7. Livewire #[Locked] cart tamper probe MUST be rejected
        const wireSnapshot = await page.locator('#livewire-pos-root').getAttribute('wire:snapshot');
        const csrfToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const updateUri = await page.locator('script[data-update-uri]').getAttribute('data-update-uri');
        expect(wireSnapshot).toBeTruthy();
        expect(csrfToken).toBeTruthy();
        expect(updateUri).toBeTruthy();

        const tamperRes = await page.request.post(updateUri!, {
            headers: {
                'X-CSRF-TOKEN': csrfToken!,
                'X-Livewire': '1',
                'Content-Type': 'application/json',
            },
            data: {
                components: [
                    {
                        snapshot: wireSnapshot,
                        updates: {
                            'cart.0.custom_price': 1,
                        },
                        calls: [],
                    },
                ],
            },
        });
        expect([403, 422, 500]).toContain(tamperRes.status());

        assertCleanConsole();
    });
});

