-- shift_overlap_audit.sql
-- Detect historical shifts that overlap temporally for the same user and store.
SELECT 
    s1.id AS shift_id_1, 
    s2.id AS shift_id_2, 
    s1.user_id, 
    s1.store_id,
    s1.opened_at AS s1_opened, 
    s1.closed_at AS s1_closed,
    s2.opened_at AS s2_opened, 
    s2.closed_at AS s2_closed
FROM shifts s1
JOIN shifts s2 ON s1.user_id = s2.user_id 
               AND s1.store_id = s2.store_id 
               AND s1.id < s2.id
WHERE (s1.opened_at < s2.closed_at OR s2.closed_at IS NULL)
  AND (s1.closed_at > s2.opened_at OR s1.closed_at IS NULL);
