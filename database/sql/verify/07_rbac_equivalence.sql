-- 07_rbac_equivalence.sql
SELECT 'FAIL' as result, count(*) as issues FROM (
    SELECT u.id
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.role_id IS NOT NULL AND r.id IS NULL
) orphans HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.role_id IS NOT NULL AND r.id IS NULL
);
