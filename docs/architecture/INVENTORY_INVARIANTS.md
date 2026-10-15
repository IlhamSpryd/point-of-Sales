# APEIRON POS — INVENTORY & STOCK BALANCES INVARIANTS

1. **Dual Stock Architecture:**
   - Products without BOM recipes track stock directly on `products.stock` and `product_stock_balances` (`tenant_id, store_id, product_id`).
   - Products with BOM recipes deduct raw ingredients on `ingredients.current_stock` and `ingredient_stock_balances` (`tenant_id, store_id, ingredient_id`).
2. **Append-Only Movement Ledgers:**
   - `stock_movements` and `ingredient_stock_movements` are strictly append-only.
   - Database triggers prevent `UPDATE` and `DELETE` on all stock movement rows.
3. **Reconciliation & Drift Prevention:**
   - Stock balance tables store authoritative quantity, baseline quantity, and baseline movement ID.
   - `inventory:reconcile-balances` command audits drift across legacy column, balance row, and baseline-adjusted movements per `(tenant_id, store_id)`.
