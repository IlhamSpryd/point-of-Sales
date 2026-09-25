-- order_total_reconciliation.sql
-- Detect discrepancies between order amounts and their constituent line items + modifiers.
SELECT 
    o.id AS order_id, 
    o.order_amount, 
    o.subtotal_amount,
    SUM(oi.line_subtotal) AS base_subtotal, 
    COALESCE(SUM(oim.line_total),0) AS modifier_total,
    (SUM(oi.line_subtotal) + COALESCE(SUM(oim.line_total),0)) AS calculated_total,
    (o.order_amount - (SUM(oi.line_subtotal) + COALESCE(SUM(oim.line_total),0))) AS discrepancy
FROM orders o 
JOIN order_items oi ON oi.order_id = o.id 
LEFT JOIN order_item_modifiers oim ON oim.order_item_id = oi.id 
GROUP BY o.id, o.order_amount, o.subtotal_amount
HAVING discrepancy <> 0;
