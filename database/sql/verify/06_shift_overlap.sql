-- 06_shift_overlap.sql
-- Detect users with multiple concurrent open shifts or temporally overlapping shifts on the same tenant and store
SELECT 'FAIL' AS result, COUNT(*) AS issues FROM (
    -- 1. Multiple open shifts for the same tenant and user
    SELECT tenant_id, user_id, COUNT(*) AS c
    FROM shifts
    WHERE status = 'open'
    GROUP BY tenant_id, user_id
    HAVING c > 1

    UNION ALL

    -- 2. Temporally overlapping shifts for the same user and store
    SELECT s1.tenant_id, s1.user_id, COUNT(*) AS c
    FROM shifts s1
    JOIN shifts s2 ON s1.tenant_id = s2.tenant_id
                  AND s1.store_id = s2.store_id
                  AND s1.user_id = s2.user_id
                  AND s1.id < s2.id
    WHERE (s1.opened_at < s2.closed_at OR s2.closed_at IS NULL)
      AND (s1.closed_at > s2.opened_at OR s1.closed_at IS NULL)
    GROUP BY s1.tenant_id, s1.user_id
) overlaps HAVING issues > 0
UNION
SELECT 'PASS' AS result, 0 AS issues
WHERE NOT EXISTS (
    SELECT 1
    FROM shifts
    WHERE status = 'open'
    GROUP BY tenant_id, user_id
    HAVING COUNT(*) > 1
)
AND NOT EXISTS (
    SELECT 1
    FROM shifts s1
    JOIN shifts s2 ON s1.tenant_id = s2.tenant_id
                  AND s1.store_id = s2.store_id
                  AND s1.user_id = s2.user_id
                  AND s1.id < s2.id
    WHERE (s1.opened_at < s2.closed_at OR s2.closed_at IS NULL)
      AND (s1.closed_at > s2.opened_at OR s1.closed_at IS NULL)
);
