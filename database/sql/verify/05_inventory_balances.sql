-- 05_inventory_balances.sql
SELECT 'FAIL' as result, count(*) as issues FROM (
    SELECT b.product_id
    FROM product_stock_balances b
    LEFT JOIN stores s ON b.store_id = s.id
    WHERE s.id IS NULL
) orphans HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM product_stock_balances b
    LEFT JOIN stores s ON b.store_id = s.id
    WHERE s.id IS NULL
);
