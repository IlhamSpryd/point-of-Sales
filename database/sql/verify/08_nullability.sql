-- 08_nullability.sql
SELECT 'FAIL' as result, count(*) as issues
FROM users
WHERE (name IS NULL OR email IS NULL) AND deleted_at IS NULL
HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM users
    WHERE (name IS NULL OR email IS NULL) AND deleted_at IS NULL
);
