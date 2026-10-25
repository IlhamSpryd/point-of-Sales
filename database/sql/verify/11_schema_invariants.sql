-- 11_schema_invariants.sql
-- Detect structural database anomalies, duplicate triggers, non-tenant-scoped unique keys, and non-composite FKs

SELECT 'FAIL' AS result, anomaly_type, table_name, details
FROM (
    -- 1. Deteksi trigger duplikat pada event & timing yang sama
    SELECT
        'DUPLICATE_TRIGGER' AS anomaly_type,
        EVENT_OBJECT_TABLE AS table_name,
        CONCAT(ACTION_TIMING, ' ', EVENT_MANIPULATION, ': ', GROUP_CONCAT(TRIGGER_NAME ORDER BY TRIGGER_NAME SEPARATOR ', ')) AS details
    FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE()
    GROUP BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
    HAVING COUNT(*) > 1

    UNION ALL

    -- 2. Deteksi index shifts_one_open_per_user_unique yang belum di-scope per tenant & store
    SELECT
        'UNSCOPED_SHIFT_UNIQUE' AS anomaly_type,
        'shifts' AS table_name,
        CONCAT('Kolom index shifts_one_open_per_user_unique: ', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ')) AS details
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'shifts'
      AND INDEX_NAME = 'shifts_one_open_per_user_unique'
    GROUP BY TABLE_NAME, INDEX_NAME
    HAVING COUNT(*) < 3 OR MAX(CASE WHEN COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) = 0 OR MAX(CASE WHEN COLUMN_NAME = 'store_id' THEN 1 ELSE 0 END) = 0

    UNION ALL

    -- 3. Deteksi hilangnya composite FK discounts -> stores
    SELECT
        'MISSING_DISCOUNT_STORE_COMPOSITE_FK' AS anomaly_type,
        'discounts' AS table_name,
        'Constraint fk_discounts_tenant_store tidak ditemukan pada tabel discounts' AS details
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'discounts'
          AND CONSTRAINT_NAME = 'fk_discounts_tenant_store'
    )

    UNION ALL

    -- 4. Deteksi idempotency key yang masih global (hanya 1 kolom, belum (tenant_id, idempotency_key))
    SELECT
        'UNSCOPED_IDEMPOTENCY_KEY' AS anomaly_type,
        TABLE_NAME AS table_name,
        CONCAT('Index ', INDEX_NAME, ' hanya memiliki ', COUNT(*), ' kolom (diharapkan >= 2 dengan tenant_id)') AS details
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('payments', 'stock_movements', 'ingredient_stock_movements', 'cash_drawer_movements')
      AND INDEX_NAME LIKE '%idempotency_key_unique%'
    GROUP BY TABLE_NAME, INDEX_NAME
    HAVING COUNT(*) < 2 OR MAX(CASE WHEN COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) = 0

    UNION ALL

    -- 5. Deteksi missing composite 3-column FK orders -> (order_items, payments, stock_movements)
    SELECT
        'MISSING_ORDER_3COL_FK' AS anomaly_type,
        tbl.required_table AS table_name,
        CONCAT('Foreign key 3-kolom ', tbl.required_fk, ' tidak ditemukan') AS details
    FROM (
        SELECT 'order_items' AS required_table, 'order_items_tenant_store_order_composite_fk' AS required_fk
        UNION ALL
        SELECT 'payments', 'payments_tenant_store_order_composite_fk'
        UNION ALL
        SELECT 'stock_movements', 'stock_movements_tenant_store_order_composite_fk'
    ) tbl
    WHERE NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = tbl.required_table
          AND CONSTRAINT_NAME = tbl.required_fk
    )
) anomalies;
