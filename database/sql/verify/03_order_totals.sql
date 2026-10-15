-- 03_order_totals.sql
-- [D2-BE-007] Rekonsiliasi rumus otoritatif orders:
--   grand_total(order_amount) === subtotal_amount - discount_amount
--                               + tax_amount + service_charge_amount (+ rounding)
-- dan cocok dengan penjumlahan order_items.order_subtotal (server-computed).
SELECT 'FAIL' AS result, COUNT(*) AS issues
FROM (
    SELECT o.id
    FROM orders o
    WHERE o.order_amount <> (
        o.subtotal_amount - o.discount_amount + o.tax_amount + o.service_charge_amount
    )
) v
HAVING issues > 0
UNION ALL
SELECT 'FAIL' AS result, COUNT(*) AS issues
FROM (
    SELECT oi.order_id
    FROM order_items oi
    JOIN orders o2 ON o2.id = oi.order_id
    GROUP BY oi.order_id
    HAVING SUM(oi.order_subtotal) <> MAX(o2.subtotal_amount)
) w
HAVING issues > 0
UNION
SELECT 'PASS' AS result, 0 AS issues
WHERE NOT EXISTS (
    SELECT 1 FROM orders o
    WHERE o.order_amount <> (
        o.subtotal_amount - o.discount_amount + o.tax_amount + o.service_charge_amount
    )
)
AND NOT EXISTS (
    SELECT 1
    FROM order_items oi
    JOIN orders o2 ON o2.id = oi.order_id
    GROUP BY oi.order_id
    HAVING SUM(oi.order_subtotal) <> MAX(o2.subtotal_amount)
);
