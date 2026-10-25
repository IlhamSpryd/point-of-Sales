-- 02_unique_keys.sql
-- Detect duplicate business unique key violations per-tenant and per-store
SELECT 'FAIL' AS result, COUNT(*) AS issues FROM (
    -- 1. Duplicate order idempotency_key per tenant
    SELECT tenant_id, idempotency_key AS identifier, COUNT(*) AS c
    FROM orders
    WHERE idempotency_key IS NOT NULL AND idempotency_key != ''
    GROUP BY tenant_id, idempotency_key
    HAVING c > 1

    UNION ALL

    -- 2. Duplicate active table name per tenant and store
    SELECT tenant_id, CONCAT(store_id, ':', table_name) AS identifier, COUNT(*) AS c
    FROM tables
    WHERE is_active = 1
    GROUP BY tenant_id, store_id, table_name
    HAVING c > 1

    UNION ALL

    -- 3. Duplicate active tenant name
    SELECT 1 AS tenant_id, name AS identifier, COUNT(*) AS c
    FROM tenants
    WHERE deleted_at IS NULL
    GROUP BY name
    HAVING c > 1

    UNION ALL

    -- 4. Duplicate role name per tenant
    SELECT tenant_id, name AS identifier, COUNT(*) AS c
    FROM roles
    GROUP BY tenant_id, name
    HAVING c > 1

    UNION ALL

    -- 5. Duplicate product code per tenant
    SELECT tenant_id, product_code AS identifier, COUNT(*) AS c
    FROM products
    WHERE product_code IS NOT NULL AND product_code != ''
    GROUP BY tenant_id, product_code
    HAVING c > 1

    UNION ALL

    -- 6. M5 Duplicate PR number per tenant
    SELECT tenant_id, pr_number AS identifier, COUNT(*) AS c
    FROM purchase_requests
    GROUP BY tenant_id, pr_number
    HAVING c > 1

    UNION ALL

    -- 7. M5 Duplicate PO number per tenant
    SELECT tenant_id, po_number AS identifier, COUNT(*) AS c
    FROM purchase_orders
    GROUP BY tenant_id, po_number
    HAVING c > 1

    UNION ALL

    -- 8. M5 Duplicate GRN number per tenant
    SELECT tenant_id, grn_number AS identifier, COUNT(*) AS c
    FROM goods_received_notes
    GROUP BY tenant_id, grn_number
    HAVING c > 1

    UNION ALL

    -- 9. M5 Duplicate supplier invoice number per supplier
    SELECT tenant_id, CONCAT(supplier_id, ':', normalized_supplier_invoice_number) AS identifier, COUNT(*) AS c
    FROM supplier_invoices
    GROUP BY tenant_id, supplier_id, normalized_supplier_invoice_number
    HAVING c > 1

    UNION ALL

    -- 10. M5 Duplicate return number per tenant
    SELECT tenant_id, return_number AS identifier, COUNT(*) AS c
    FROM purchase_returns
    GROUP BY tenant_id, return_number
    HAVING c > 1

    UNION ALL

    -- 11. M5 Duplicate debit note number per tenant
    SELECT tenant_id, debit_note_number AS identifier, COUNT(*) AS c
    FROM purchase_debit_notes
    GROUP BY tenant_id, debit_note_number
    HAVING c > 1

    UNION ALL

    -- 12. M5 Duplicate cost code per tenant
    SELECT tenant_id, cost_code AS identifier, COUNT(*) AS c
    FROM purchase_additional_costs
    GROUP BY tenant_id, cost_code
    HAVING c > 1
) dups HAVING issues > 0
UNION
SELECT 'PASS' AS result, 0 AS issues
WHERE NOT EXISTS (
    SELECT 1 FROM (
        SELECT tenant_id, idempotency_key AS identifier
        FROM orders
        WHERE idempotency_key IS NOT NULL AND idempotency_key != ''
        GROUP BY tenant_id, idempotency_key
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, CONCAT(store_id, ':', table_name) AS identifier
        FROM tables
        WHERE is_active = 1
        GROUP BY tenant_id, store_id, table_name
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT 1 AS tenant_id, name AS identifier
        FROM tenants
        WHERE deleted_at IS NULL
        GROUP BY name
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, name AS identifier
        FROM roles
        GROUP BY tenant_id, name
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, product_code AS identifier
        FROM products
        WHERE product_code IS NOT NULL AND product_code != ''
        GROUP BY tenant_id, product_code
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, pr_number AS identifier
        FROM purchase_requests
        GROUP BY tenant_id, pr_number
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, po_number AS identifier
        FROM purchase_orders
        GROUP BY tenant_id, po_number
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, grn_number AS identifier
        FROM goods_received_notes
        GROUP BY tenant_id, grn_number
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, CONCAT(supplier_id, ':', normalized_supplier_invoice_number) AS identifier
        FROM supplier_invoices
        GROUP BY tenant_id, supplier_id, normalized_supplier_invoice_number
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, return_number AS identifier
        FROM purchase_returns
        GROUP BY tenant_id, return_number
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, debit_note_number AS identifier
        FROM purchase_debit_notes
        GROUP BY tenant_id, debit_note_number
        HAVING COUNT(*) > 1

        UNION ALL

        SELECT tenant_id, cost_code AS identifier
        FROM purchase_additional_costs
        GROUP BY tenant_id, cost_code
        HAVING COUNT(*) > 1
    ) all_dups
);
