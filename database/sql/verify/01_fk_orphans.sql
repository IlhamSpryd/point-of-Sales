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
    WHERE t.id IS NULL OR tb.store_id IS NULL OR s.id IS NULL

    UNION ALL

    -- 7b. Orders with table mismatching tenant or store
    SELECT o.id, 'order_table_mismatch_tenant_or_store' AS reason
    FROM orders o
    JOIN tables tb ON o.table_id = tb.id
    WHERE o.tenant_id != tb.tenant_id OR o.store_id != tb.store_id

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

    UNION ALL

    -- 18. M5 Purchase Requests missing tenant or store
    SELECT pr.id, 'm5_pr_missing_tenant_or_store' AS reason
    FROM purchase_requests pr
    LEFT JOIN tenants t ON pr.tenant_id = t.id
    LEFT JOIN stores s ON pr.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL

    UNION ALL

    -- 19. M5 Purchase Request Lines missing parent
    SELECT prl.id, 'm5_pr_line_missing_parent' AS reason
    FROM purchase_request_lines prl
    LEFT JOIN purchase_requests pr ON prl.purchase_request_id = pr.id
    WHERE pr.id IS NULL

    UNION ALL

    -- 20. M5 Purchase Orders missing tenant, store, or supplier
    SELECT po.id, 'm5_po_missing_parent_entities' AS reason
    FROM purchase_orders po
    LEFT JOIN tenants t ON po.tenant_id = t.id
    LEFT JOIN stores s ON po.store_id = s.id
    LEFT JOIN suppliers sup ON po.supplier_id = sup.id
    WHERE t.id IS NULL OR s.id IS NULL OR sup.id IS NULL

    UNION ALL

    -- 21. M5 Purchase Order Lines missing parent
    SELECT pol.id, 'm5_po_line_missing_parent' AS reason
    FROM purchase_order_lines pol
    LEFT JOIN purchase_orders po ON pol.purchase_order_id = po.id
    WHERE po.id IS NULL

    UNION ALL

    -- 22. M5 Goods Received Notes missing tenant, store, or purchase_order
    SELECT grn.id, 'm5_grn_missing_parent_entities' AS reason
    FROM goods_received_notes grn
    LEFT JOIN tenants t ON grn.tenant_id = t.id
    LEFT JOIN stores s ON grn.store_id = s.id
    LEFT JOIN purchase_orders po ON grn.purchase_order_id = po.id
    WHERE t.id IS NULL OR s.id IS NULL OR po.id IS NULL

    UNION ALL

    -- 23. M5 Goods Received Note Lines missing parent
    SELECT grnl.id, 'm5_grn_line_missing_parent' AS reason
    FROM goods_received_note_lines grnl
    LEFT JOIN goods_received_notes grn ON grnl.goods_received_note_id = grn.id
    WHERE grn.id IS NULL

    UNION ALL

    -- 24. M5 Supplier Invoices missing tenant or supplier
    SELECT si.id, 'm5_invoice_missing_tenant_or_supplier' AS reason
    FROM supplier_invoices si
    LEFT JOIN tenants t ON si.tenant_id = t.id
    LEFT JOIN suppliers sup ON si.supplier_id = sup.id
    WHERE t.id IS NULL OR sup.id IS NULL

    UNION ALL

    -- 25. M5 Supplier Invoice Lines missing parent
    SELECT sil.id, 'm5_invoice_line_missing_parent' AS reason
    FROM supplier_invoice_lines sil
    LEFT JOIN supplier_invoices si ON sil.supplier_invoice_id = si.id
    WHERE si.id IS NULL

    UNION ALL

    -- 26. M5 Purchase Debit Notes missing tenant or supplier
    SELECT pdn.id, 'm5_debit_note_missing_tenant_or_supplier' AS reason
    FROM purchase_debit_notes pdn
    LEFT JOIN tenants t ON pdn.tenant_id = t.id
    LEFT JOIN suppliers sup ON pdn.supplier_id = sup.id
    WHERE t.id IS NULL OR sup.id IS NULL

    UNION ALL

    -- 27. M5 Purchase Additional Costs missing tenant or store
    SELECT pac.id, 'm5_cost_missing_tenant_or_store' AS reason
    FROM purchase_additional_costs pac
    LEFT JOIN tenants t ON pac.tenant_id = t.id
    LEFT JOIN stores s ON pac.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL

    UNION ALL

    -- 28. M5 Reorder Suggestions missing tenant or store
    SELECT rs.id, 'm5_reorder_missing_tenant_or_store' AS reason
    FROM reorder_suggestions rs
    LEFT JOIN tenants t ON rs.tenant_id = t.id
    LEFT JOIN stores s ON rs.store_id = s.id
    WHERE t.id IS NULL OR s.id IS NULL
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
        SELECT tb.id FROM tables tb LEFT JOIN tenants t ON tb.tenant_id = t.id LEFT JOIN stores s ON tb.store_id = s.id WHERE t.id IS NULL OR tb.store_id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT o.id FROM orders o JOIN tables tb ON o.table_id = tb.id WHERE o.tenant_id != tb.tenant_id OR o.store_id != tb.store_id
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
        UNION ALL
        SELECT pr.id FROM purchase_requests pr LEFT JOIN tenants t ON pr.tenant_id = t.id LEFT JOIN stores s ON pr.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT prl.id FROM purchase_request_lines prl LEFT JOIN purchase_requests pr ON prl.purchase_request_id = pr.id WHERE pr.id IS NULL
        UNION ALL
        SELECT po.id FROM purchase_orders po LEFT JOIN tenants t ON po.tenant_id = t.id LEFT JOIN stores s ON po.store_id = s.id LEFT JOIN suppliers sup ON po.supplier_id = sup.id WHERE t.id IS NULL OR s.id IS NULL OR sup.id IS NULL
        UNION ALL
        SELECT pol.id FROM purchase_order_lines pol LEFT JOIN purchase_orders po ON pol.purchase_order_id = po.id WHERE po.id IS NULL
        UNION ALL
        SELECT grn.id FROM goods_received_notes grn LEFT JOIN tenants t ON grn.tenant_id = t.id LEFT JOIN stores s ON grn.store_id = s.id LEFT JOIN purchase_orders po ON grn.purchase_order_id = po.id WHERE t.id IS NULL OR s.id IS NULL OR po.id IS NULL
        UNION ALL
        SELECT grnl.id FROM goods_received_note_lines grnl LEFT JOIN goods_received_notes grn ON grnl.goods_received_note_id = grn.id WHERE grn.id IS NULL
        UNION ALL
        SELECT si.id FROM supplier_invoices si LEFT JOIN tenants t ON si.tenant_id = t.id LEFT JOIN suppliers sup ON si.supplier_id = sup.id WHERE t.id IS NULL OR sup.id IS NULL
        UNION ALL
        SELECT sil.id FROM supplier_invoice_lines sil LEFT JOIN supplier_invoices si ON sil.supplier_invoice_id = si.id WHERE si.id IS NULL
        UNION ALL
        SELECT pdn.id FROM purchase_debit_notes pdn LEFT JOIN tenants t ON pdn.tenant_id = t.id LEFT JOIN suppliers sup ON pdn.supplier_id = sup.id WHERE t.id IS NULL OR sup.id IS NULL
        UNION ALL
        SELECT pac.id FROM purchase_additional_costs pac LEFT JOIN tenants t ON pac.tenant_id = t.id LEFT JOIN stores s ON pac.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
        UNION ALL
        SELECT rs.id FROM reorder_suggestions rs LEFT JOIN tenants t ON rs.tenant_id = t.id LEFT JOIN stores s ON rs.store_id = s.id WHERE t.id IS NULL OR s.id IS NULL
    ) all_orphans
);
