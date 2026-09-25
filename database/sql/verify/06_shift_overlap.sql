-- 06_shift_overlap.sql
SELECT 'FAIL' as result, count(*) as issues FROM (
    SELECT s1.id 
    FROM shifts s1
    JOIN shifts s2 ON s1.user_id = s2.user_id AND s1.store_id = s2.store_id AND s1.id < s2.id
    WHERE (s1.opened_at < s2.closed_at OR s2.closed_at IS NULL)
      AND (s1.closed_at > s2.opened_at OR s1.closed_at IS NULL)
) overlaps HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT s1.id 
    FROM shifts s1
    JOIN shifts s2 ON s1.user_id = s2.user_id AND s1.store_id = s2.store_id AND s1.id < s2.id
    WHERE (s1.opened_at < s2.closed_at OR s2.closed_at IS NULL)
      AND (s1.closed_at > s2.opened_at OR s1.closed_at IS NULL)
);
