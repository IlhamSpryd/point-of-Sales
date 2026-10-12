-- 01_fk_orphans.sql
SELECT 'FAIL' as result, count(*) as issues FROM (
    SELECT u.id
    FROM users u
    LEFT JOIN tenants t ON u.tenant_id = t.id
    WHERE u.tenant_id IS NOT NULL AND t.id IS NULL
) orphans HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM users u
    LEFT JOIN tenants t ON u.tenant_id = t.id
    WHERE u.tenant_id IS NOT NULL AND t.id IS NULL
);
