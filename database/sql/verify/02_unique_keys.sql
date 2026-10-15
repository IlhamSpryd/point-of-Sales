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
    ) all_dups
);
