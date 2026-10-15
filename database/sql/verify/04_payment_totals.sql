-- 04_payment_totals.sql
-- [D2-BE-007] Order lunas (paid/completed) yang total payments berstatus
-- captured/settled-nya TIDAK sama dengan orders.order_amount, atau order lunas
-- tanpa payment sama sekali, atau total payment melebihi tagihan.
SELECT 'FAIL' AS result, COUNT(*) AS issues
FROM (
    SELECT o.id
    FROM orders o
    LEFT JOIN (
        SELECT order_id, SUM(amount) AS paid_total
        FROM payments
        WHERE status IN ('captured', 'settled')
        GROUP BY order_id
    ) p ON p.order_id = o.id
    WHERE o.order_status IN ('paid', 'completed')
      AND (p.paid_total IS NULL OR p.paid_total <> o.order_amount)
) v
HAVING issues > 0
UNION
SELECT 'PASS' AS result, 0 AS issues
WHERE NOT EXISTS (
    SELECT 1
    FROM orders o
    LEFT JOIN (
        SELECT order_id, SUM(amount) AS paid_total
        FROM payments
        WHERE status IN ('captured', 'settled')
        GROUP BY order_id
    ) p ON p.order_id = o.id
    WHERE o.order_status IN ('paid', 'completed')
      AND (p.paid_total IS NULL OR p.paid_total <> o.order_amount)
);
