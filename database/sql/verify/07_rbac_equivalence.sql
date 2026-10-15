-- 07_rbac_equivalence.sql
SELECT 'FAIL' as result, count(*) as issues FROM (
    -- 1. Users with role from mismatched tenant or missing role
    SELECT u.id, 'user_role_mismatch' as reason
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.role_id IS NOT NULL AND (r.id IS NULL OR u.tenant_id != r.tenant_id)
    UNION ALL
    -- 2. Orphan role_permissions (role missing or permission missing)
    SELECT rp.role_id, 'orphan_role_permission' as reason
    FROM role_permissions rp
    LEFT JOIN roles r ON rp.role_id = r.id
    LEFT JOIN permissions p ON rp.permission_id = p.id
    WHERE r.id IS NULL OR p.id IS NULL
    UNION ALL
    -- 3. Cross-tenant user_stores (store or user tenant mismatch)
    SELECT us.user_id, 'user_store_tenant_mismatch' as reason
    FROM user_stores us
    LEFT JOIN users u ON us.user_id = u.id
    LEFT JOIN stores s ON us.store_id = s.id
    WHERE u.id IS NULL OR s.id IS NULL OR us.tenant_id != u.tenant_id OR us.tenant_id != s.tenant_id
) discrepancies HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1 FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.role_id IS NOT NULL AND (r.id IS NULL OR u.tenant_id != r.tenant_id)
)
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    LEFT JOIN roles r ON rp.role_id = r.id
    LEFT JOIN permissions p ON rp.permission_id = p.id
    WHERE r.id IS NULL OR p.id IS NULL
)
AND NOT EXISTS (
    SELECT 1 FROM user_stores us
    LEFT JOIN users u ON us.user_id = u.id
    LEFT JOIN stores s ON us.store_id = s.id
    WHERE u.id IS NULL OR s.id IS NULL OR us.tenant_id != u.tenant_id OR us.tenant_id != s.tenant_id
);
