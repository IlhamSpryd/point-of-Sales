-- 03_order_totals.sql
SELECT 'FAIL' as result, count(*) as issues
FROM orders
WHERE order_amount < 0
HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM orders
    WHERE order_amount < 0
);
