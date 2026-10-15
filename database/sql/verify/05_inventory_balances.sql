-- 05_inventory_balances.sql
-- Detect discrepancies between stock balances, movement ledgers, and legacy mirror columns, as well as negative stock violations.
SELECT 'FAIL' AS result, COUNT(*) AS issues FROM (
    -- 1. Product stock balance drift from baseline + stock movements
    SELECT psb.product_id, psb.store_id, 'product_balance_ledger_drift' AS reason
    FROM product_stock_balances psb
    WHERE ROUND(psb.quantity, 4) <> ROUND(
        psb.baseline_quantity + COALESCE((
            SELECT SUM(sm.quantity)
            FROM stock_movements sm
            WHERE sm.tenant_id = psb.tenant_id
              AND sm.store_id = psb.store_id
              AND sm.product_id = psb.product_id
              AND sm.id > psb.baseline_movement_id
        ), 0),
        4
    )

    UNION ALL

    -- 2. Ingredient stock balance drift from baseline + ingredient stock movements
    SELECT isb.ingredient_id, isb.store_id, 'ingredient_balance_ledger_drift' AS reason
    FROM ingredient_stock_balances isb
    WHERE ROUND(isb.quantity, 4) <> ROUND(
        isb.baseline_quantity + COALESCE((
            SELECT SUM(ism.quantity)
            FROM ingredient_stock_movements ism
            WHERE ism.tenant_id = isb.tenant_id
              AND ism.store_id = isb.store_id
              AND ism.ingredient_id = isb.ingredient_id
              AND ism.id > isb.baseline_movement_id
        ), 0),
        4
    )

    UNION ALL

    -- 3. Product legacy stock mirror column mismatch with balance sum
    SELECT p.id, 0 AS store_id, 'product_legacy_mirror_mismatch' AS reason
    FROM products p
    WHERE EXISTS (SELECT 1 FROM product_stock_balances psb WHERE psb.tenant_id = p.tenant_id AND psb.product_id = p.id)
      AND ROUND(p.stock, 4) <> ROUND(COALESCE((
          SELECT SUM(psb2.quantity)
          FROM product_stock_balances psb2
          WHERE psb2.tenant_id = p.tenant_id AND psb2.product_id = p.id
      ), 0), 4)

    UNION ALL

    -- 4. Ingredient legacy current_stock mirror mismatch with balance sum
    SELECT i.id, 0 AS store_id, 'ingredient_legacy_mirror_mismatch' AS reason
    FROM ingredients i
    WHERE EXISTS (SELECT 1 FROM ingredient_stock_balances isb WHERE isb.tenant_id = i.tenant_id AND isb.ingredient_id = i.id)
      AND ROUND(i.current_stock, 4) <> ROUND(COALESCE((
          SELECT SUM(isb2.quantity)
          FROM ingredient_stock_balances isb2
          WHERE isb2.tenant_id = i.tenant_id AND isb2.ingredient_id = i.id
      ), 0), 4)

    UNION ALL

    -- 5. Negative stock violations
    SELECT psb.product_id, psb.store_id, 'negative_product_balance' AS reason
    FROM product_stock_balances psb
    WHERE psb.quantity < 0
    UNION ALL
    SELECT isb.ingredient_id, isb.store_id, 'negative_ingredient_balance' AS reason
    FROM ingredient_stock_balances isb
    WHERE isb.quantity < 0
    UNION ALL
    SELECT p.id, 0 AS store_id, 'negative_product_legacy_stock' AS reason
    FROM products p
    WHERE p.stock < 0
    UNION ALL
    SELECT i.id, 0 AS store_id, 'negative_ingredient_legacy_stock' AS reason
    FROM ingredients i
    WHERE i.current_stock < 0
) discrepancies HAVING issues > 0
UNION
SELECT 'PASS' AS result, 0 AS issues
WHERE NOT EXISTS (
    SELECT 1 FROM product_stock_balances psb
    WHERE ROUND(psb.quantity, 4) <> ROUND(
        psb.baseline_quantity + COALESCE((
            SELECT SUM(sm.quantity)
            FROM stock_movements sm
            WHERE sm.tenant_id = psb.tenant_id
              AND sm.store_id = psb.store_id
              AND sm.product_id = psb.product_id
              AND sm.id > psb.baseline_movement_id
        ), 0),
        4
    )
    OR psb.quantity < 0
)
AND NOT EXISTS (
    SELECT 1 FROM ingredient_stock_balances isb
    WHERE ROUND(isb.quantity, 4) <> ROUND(
        isb.baseline_quantity + COALESCE((
            SELECT SUM(ism.quantity)
            FROM ingredient_stock_movements ism
            WHERE ism.tenant_id = isb.tenant_id
              AND ism.store_id = isb.store_id
              AND ism.ingredient_id = isb.ingredient_id
              AND ism.id > isb.baseline_movement_id
        ), 0),
        4
    )
    OR isb.quantity < 0
)
AND NOT EXISTS (
    SELECT 1 FROM products p
    WHERE (
        EXISTS (SELECT 1 FROM product_stock_balances psb WHERE psb.tenant_id = p.tenant_id AND psb.product_id = p.id)
        AND ROUND(p.stock, 4) <> ROUND(COALESCE((
            SELECT SUM(psb2.quantity)
            FROM product_stock_balances psb2
            WHERE psb2.tenant_id = p.tenant_id AND psb2.product_id = p.id
        ), 0), 4)
    )
    OR p.stock < 0
)
AND NOT EXISTS (
    SELECT 1 FROM ingredients i
    WHERE (
        EXISTS (SELECT 1 FROM ingredient_stock_balances isb WHERE isb.tenant_id = i.tenant_id AND isb.ingredient_id = i.id)
        AND ROUND(i.current_stock, 4) <> ROUND(COALESCE((
            SELECT SUM(isb2.quantity)
            FROM ingredient_stock_balances isb2
            WHERE isb2.tenant_id = i.tenant_id AND isb2.ingredient_id = i.id
        ), 0), 4)
    )
    OR i.current_stock < 0
);
