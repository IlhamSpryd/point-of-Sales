-- 08_nullability.sql
-- Detect unexpected NULL values on tenant_id and store_id in transactional and master tables
SELECT 'FAIL' AS result, COUNT(*) AS issues FROM (
    -- 1. Master tables where tenant_id cannot be null
    SELECT id, 'products_tenant_null' AS reason FROM products WHERE tenant_id IS NULL
    UNION ALL
    SELECT id, 'ingredients_tenant_null' AS reason FROM ingredients WHERE tenant_id IS NULL
    UNION ALL
    SELECT id, 'categories_tenant_null' AS reason FROM categories WHERE tenant_id IS NULL
    UNION ALL
    SELECT id, 'customers_tenant_null' AS reason FROM customers WHERE tenant_id IS NULL
    UNION ALL
    SELECT id, 'roles_tenant_null' AS reason FROM roles WHERE tenant_id IS NULL

    UNION ALL

    -- 2. Branch-owned transactional tables where tenant_id or store_id cannot be null
    SELECT id, 'orders_tenant_or_store_null' AS reason FROM orders WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT id, 'shifts_tenant_or_store_null' AS reason FROM shifts WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT id, 'payments_tenant_or_store_null' AS reason FROM payments WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT id, 'cash_drawer_movements_tenant_or_store_null' AS reason FROM cash_drawer_movements WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT id, 'stock_movements_tenant_or_store_null' AS reason FROM stock_movements WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT id, 'ingredient_stock_movements_tenant_or_store_null' AS reason FROM ingredient_stock_movements WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT id, 'tables_tenant_or_store_null' AS reason FROM tables WHERE tenant_id IS NULL OR store_id IS NULL
) nulls HAVING issues > 0
UNION
SELECT 'PASS' AS result, 0 AS issues
WHERE NOT EXISTS (
    SELECT 1 FROM products WHERE tenant_id IS NULL
    UNION ALL
    SELECT 1 FROM ingredients WHERE tenant_id IS NULL
    UNION ALL
    SELECT 1 FROM categories WHERE tenant_id IS NULL
    UNION ALL
    SELECT 1 FROM customers WHERE tenant_id IS NULL
    UNION ALL
    SELECT 1 FROM roles WHERE tenant_id IS NULL
    UNION ALL
    SELECT 1 FROM orders WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT 1 FROM shifts WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT 1 FROM payments WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT 1 FROM cash_drawer_movements WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT 1 FROM stock_movements WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT 1 FROM ingredient_stock_movements WHERE tenant_id IS NULL OR store_id IS NULL
    UNION ALL
    SELECT 1 FROM tables WHERE tenant_id IS NULL OR store_id IS NULL
);
