-- 09_indexes.sql
-- Verify that required architectural multi-tenant and composite unique indexes exist in information_schema.
SELECT 'FAIL' AS result, COUNT(*) AS missing_count FROM (
    SELECT 'stores_tenant_id_id_unique' AS expected_index
    WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stores' AND INDEX_NAME = 'stores_tenant_id_id_unique'
    )
    UNION ALL
    SELECT 'orders_tenant_idempotency_unique' AS expected_index
    WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME IN ('orders_tenant_id_idempotency_key_unique', 'orders_tenant_idempotency_key_unique')
    )
    UNION ALL
    SELECT 'shifts_one_open_per_user_unique' AS expected_index
    WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shifts' AND INDEX_NAME = 'shifts_one_open_per_user_unique'
    )
    UNION ALL
    SELECT 'product_stock_balances_primary' AS expected_index
    WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_stock_balances' AND INDEX_NAME = 'PRIMARY'
    )
    UNION ALL
    SELECT 'ingredient_stock_balances_primary' AS expected_index
    WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ingredient_stock_balances' AND INDEX_NAME = 'PRIMARY'
    )
) missing_indexes HAVING missing_count > 0
UNION
SELECT 'PASS' AS result, 0 AS missing_count
WHERE (
    SELECT COUNT(*) FROM (
        SELECT 'stores_tenant_id_id_unique' AS expected_index
        WHERE NOT EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stores' AND INDEX_NAME = 'stores_tenant_id_id_unique'
        )
        UNION ALL
        SELECT 'orders_tenant_idempotency_unique' AS expected_index
        WHERE NOT EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME IN ('orders_tenant_id_idempotency_key_unique', 'orders_tenant_idempotency_key_unique')
        )
        UNION ALL
        SELECT 'shifts_one_open_per_user_unique' AS expected_index
        WHERE NOT EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shifts' AND INDEX_NAME = 'shifts_one_open_per_user_unique'
        )
        UNION ALL
        SELECT 'product_stock_balances_primary' AS expected_index
        WHERE NOT EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_stock_balances' AND INDEX_NAME = 'PRIMARY'
        )
        UNION ALL
        SELECT 'ingredient_stock_balances_primary' AS expected_index
        WHERE NOT EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ingredient_stock_balances' AND INDEX_NAME = 'PRIMARY'
        )
    ) m
) = 0;
