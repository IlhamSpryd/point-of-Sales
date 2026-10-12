-- 09_indexes.sql
SELECT 'FAIL' as result, count(*) as issues FROM (
    SELECT t.id
    FROM tables t
    LEFT JOIN tenants te ON t.tenant_id = te.id
    WHERE te.id IS NULL
) orphans HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1
    FROM tables t
    LEFT JOIN tenants te ON t.tenant_id = te.id
    WHERE te.id IS NULL
);
