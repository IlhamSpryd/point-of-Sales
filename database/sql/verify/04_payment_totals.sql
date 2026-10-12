-- 04_payment_totals.sql
SELECT 'FAIL' as result, count(*) as issues
FROM payments
WHERE amount < 0
HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM payments
    WHERE amount < 0
);
