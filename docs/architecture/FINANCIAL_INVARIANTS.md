# APEIRON POS — FINANCIAL & TRANSACTION INVARIANTS

1. **Order Total Calculations:**
   - Subtotal calculated server-side from active catalog product prices and modifier extra prices.
   - Client-provided amounts are never trusted blindly.
2. **Payments & Cash Drawer:**
   - Multi-leg payments recorded in `payments`.
   - Overpayment guard trigger `trg_payments_prevent_overpayment` prevents captured payment sums from exceeding `order_amount`.
   - `cash_drawer_movements` ledger tracks cash floats, cash-in, cash-out, and shift reconciliation.
3. **Idempotency:**
   - `orders.idempotency_key` is unique per tenant (`orders_tenant_idempotency_key_unique`).
   - Duplicate submissions are detected and return the existing order safely without duplicate billing.
