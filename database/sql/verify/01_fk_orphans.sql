-- 01_fk_orphans.sql
-- Detect orphan foreign keys across master and transactional tables
SELECT 'FAIL' AS result, COUNT(*) AS issues FROM (
    -- 1. Users missing tenant
    SELECT u.id, 'user_missing_tenant' AS reason
    FROM users u
    LEFT JOIN tenants t ON u.tenant_id = t.id
    WHERE u.tenant_id IS NOT NULL AND t.id IS NULL

    UNION ALL

    -- 2. Orders missing tenant or store
    SELECT o.id, 'order_missing_tenant_or_store' AS reason
    FROM orders o
    LEFT JOIN tenants t ON o.tenant_id = t.id
    LEFT JOIN stores s ON o.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL

    UNION ALL

    -- 3. Order items missing order or product
    SELECT oi.id, 'order_item_missing_order_or_product' AS reason
    FROM order_items oi
    LEFT JOIN orders o ON oi.order_id = o.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.id IS NULL OR p.id IS NULL

    UNION ALL

    -- 4. Payments missing order
    SELECT p.id, 'payment_missing_order' AS reason
    FROM payments p
    LEFT JOIN orders o ON p.order_id = o.id
    WHERE o.id IS NULL

    UNION ALL

    -- 5. Shifts missing tenant, store, or user
    SELECT sh.id, 'shift_missing_tenant_store_user' AS reason
    FROM shifts sh
    LEFT JOIN tenants t ON sh.tenant_id = t.id
    LEFT JOIN stores s ON sh.store_id = s.id
    LEFT JOIN users u ON sh.user_id = u.id
    WHERE t.id IS NULL OR s.id IS NULL OR u.id IS NULL

    UNION ALL

    -- 6. Cash drawer movements missing shift
    SELECT cdm.id, 'cdm_missing_shift' AS reason
    FROM cash_drawer_movements cdm
    LEFT JOIN shifts sh ON cdm.shift_id = sh.id
    WHERE sh.id IS NULL

    UNION ALL

    -- 7. Tables missing tenant or store
    SELECT tb.id, 'table_missing_tenant_or_store' AS reason
    FROM tables tb
    LEFT JOIN tenants t ON tb.tenant_id = t.id
    LEFT JOIN stores s ON tb.store_id = s.id
    WHERE t.id IS NULL OR (tb.store_id IS NOT NULL AND s.id IS NULL)
) orphans HAVING issues > 0
UNION
SELECT 'PASS' AS result, 0 AS issues
WHERE NOT EXISTS (
    SELECT 1 FROM (
        SELECT u.id FROM users u LEFT JOIN tenants t ON u.tenant_id = t.id WHERE u.tenant_id IS NOT NULL AND t.id IS NULL
        UNION ALL
        SELECT o.id FROM orders o LEFT JOIN tenants t ON o.tenant_id = t.id LEFT JOIN stores s ON o.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT oi.id FROM order_items oi LEFT JOIN orders o ON oi.order_id = o.id LEFT JOIN products p ON oi.product_id = p.id WHERE o.id IS NULL OR p.id IS NULL
        UNION ALL
        SELECT p.id FROM payments p LEFT JOIN orders o ON p.order_id = o.id WHERE o.id IS NULL
        UNION ALL
        SELECT sh.id FROM shifts sh LEFT JOIN tenants t ON sh.tenant_id = t.id LEFT JOIN stores s ON sh.store_id = s.id LEFT JOIN users u ON sh.user_id = u.id WHERE t.id IS NULL OR s.id IS NULL OR u.id IS NULL
        UNION ALL
        SELECT cdm.id FROM cash_drawer_movements cdm LEFT JOIN shifts sh ON cdm.shift_id = sh.id WHERE sh.id IS NULL
        UNION ALL
        SELECT tb.id FROM tables tb LEFT JOIN tenants t ON tb.tenant_id = t.id LEFT JOIN stores s ON tb.store_id = s.id WHERE t.id IS NULL OR (tb.store_id IS NOT NULL AND s.id IS NULL)
    ) all_orphans
);
