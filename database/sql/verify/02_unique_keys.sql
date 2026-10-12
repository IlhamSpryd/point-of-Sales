-- 02_unique_keys.sql
SELECT 'FAIL' as result, count(*) as issues FROM (
    SELECT email, count(*) as c
    FROM users
    WHERE deleted_at IS NULL
    GROUP BY email
    HAVING c > 1
) dups HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM users
    WHERE deleted_at IS NULL
    GROUP BY email
    HAVING count(*) > 1
);
