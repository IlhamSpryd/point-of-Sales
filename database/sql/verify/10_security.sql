-- 10_security.sql
SELECT 'FAIL' as result, count(*) as issues FROM users WHERE pin_hash IS NULL OR password_hash IS NULL HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues WHERE NOT EXISTS (SELECT 1 FROM users WHERE pin_hash IS NULL OR password_hash IS NULL);
