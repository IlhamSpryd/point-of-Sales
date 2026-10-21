-- 01_fk_orphans.sql
-- Detect orphan foreign keys across master and transactional tables including Module 4 tables
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

    UNION ALL

    -- 8. Goods Receipts missing tenant or store
    SELECT gr.id, 'goods_receipt_missing_tenant_or_store' AS reason
    FROM goods_receipts gr
    LEFT JOIN tenants t ON gr.tenant_id = t.id
    LEFT JOIN stores s ON gr.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL

    UNION ALL

    -- 9. Goods Receipt Items missing goods_receipt
    SELECT gri.id, 'goods_receipt_item_missing_parent' AS reason
    FROM goods_receipt_items gri
    LEFT JOIN goods_receipts gr ON gri.goods_receipt_id = gr.id
    WHERE gr.id IS NULL

    UNION ALL

    -- 10. Stock Adjustments missing tenant or store
    SELECT sa.id, 'stock_adjustment_missing_tenant_or_store' AS reason
    FROM stock_adjustments sa
    LEFT JOIN tenants t ON sa.tenant_id = t.id
    LEFT JOIN stores s ON sa.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL

    UNION ALL

    -- 11. Stock Adjustment Items missing parent
    SELECT sai.id, 'stock_adjustment_item_missing_parent' AS reason
    FROM stock_adjustment_items sai
    LEFT JOIN stock_adjustments sa ON sai.stock_adjustment_id = sa.id
    WHERE sa.id IS NULL

    UNION ALL

    -- 12. Stock Opnames missing tenant or store
    SELECT so.id, 'stock_opname_missing_tenant_or_store' AS reason
    FROM stock_opnames so
    LEFT JOIN tenants t ON so.tenant_id = t.id
    LEFT JOIN stores s ON so.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL

    UNION ALL

    -- 13. Stock Opname Items missing parent
    SELECT soi.id, 'stock_opname_item_missing_parent' AS reason
    FROM stock_opname_items soi
    LEFT JOIN stock_opnames so ON soi.stock_opname_id = so.id
    WHERE so.id IS NULL

    UNION ALL

    -- 14. Stock Transfers missing tenant or stores
    SELECT st.id, 'stock_transfer_missing_tenant_or_stores' AS reason
    FROM stock_transfers st
    LEFT JOIN tenants t ON st.tenant_id = t.id
    LEFT JOIN stores s1 ON st.from_store_id = s1.id
    LEFT JOIN stores s2 ON st.to_store_id = s2.id
    WHERE t.id IS NULL OR s1.id IS NULL OR s2.id IS NULL

    UNION ALL

    -- 15. Stock Transfer Items missing parent
    SELECT sti.id, 'stock_transfer_item_missing_parent' AS reason
    FROM stock_transfer_items sti
    LEFT JOIN stock_transfers st ON sti.stock_transfer_id = st.id
    WHERE st.id IS NULL

    UNION ALL

    -- 16. Supplier Returns missing tenant or store
    SELECT sr.id, 'supplier_return_missing_tenant_or_store' AS reason
    FROM supplier_returns sr
    LEFT JOIN tenants t ON sr.tenant_id = t.id
    LEFT JOIN stores s ON sr.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL

    UNION ALL

    -- 17. Supplier Return Items missing parent
    SELECT sri.id, 'supplier_return_item_missing_parent' AS reason
    FROM supplier_return_items sri
    LEFT JOIN supplier_returns sr ON sri.supplier_return_id = sr.id
    WHERE sr.id IS NULL
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
        UNION ALL
        SELECT gr.id FROM goods_receipts gr LEFT JOIN tenants t ON gr.tenant_id = t.id LEFT JOIN stores s ON gr.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT gri.id FROM goods_receipt_items gri LEFT JOIN goods_receipts gr ON gri.goods_receipt_id = gr.id WHERE gr.id IS NULL
        UNION ALL
        SELECT sa.id FROM stock_adjustments sa LEFT JOIN tenants t ON sa.tenant_id = t.id LEFT JOIN stores s ON sa.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT sai.id FROM stock_adjustment_items sai LEFT JOIN stock_adjustments sa ON sai.stock_adjustment_id = sa.id WHERE sa.id IS NULL
        UNION ALL
        SELECT so.id FROM stock_opnames so LEFT JOIN tenants t ON so.tenant_id = t.id LEFT JOIN stores s ON so.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT soi.id FROM stock_opname_items soi LEFT JOIN stock_opnames so ON soi.stock_opname_id = so.id WHERE so.id IS NULL
        UNION ALL
        SELECT st.id FROM stock_transfers st LEFT JOIN tenants t ON st.tenant_id = t.id LEFT JOIN stores s1 ON st.from_store_id = s1.id LEFT JOIN stores s2 ON st.to_store_id = s2.id WHERE t.id IS NULL OR s1.id IS NULL OR s2.id IS NULL
        UNION ALL
        SELECT sti.id FROM stock_transfer_items sti LEFT JOIN stock_transfers st ON sti.stock_transfer_id = st.id WHERE st.id IS NULL
        UNION ALL
        SELECT sr.id FROM supplier_returns sr LEFT JOIN tenants t ON sr.tenant_id = t.id LEFT JOIN stores s ON sr.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT sri.id FROM supplier_return_items sri LEFT JOIN supplier_returns sr ON sri.supplier_return_id = sr.id WHERE sr.id IS NULL
    ) all_orphans
);
