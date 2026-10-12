-- 10_security.sql
SELECT 'FAIL' as result, count(*) as issues
FROM users u
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
  AND u.email NOT LIKE '%@system.local'
  AND u.password_hash IS NULL
  AND u.password IS NULL
  AND u.pin_hash IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM owner_onboarding_tokens t
      WHERE t.user_id = u.id
        AND t.consumed_at IS NULL
        AND t.expires_at > NOW()
  )
HAVING issues > 0
UNION
SELECT 'PASS' as result, 0 as issues
WHERE NOT EXISTS (
    SELECT 1 FROM users u
    WHERE u.deleted_at IS NULL
      AND u.is_active = 1
      AND u.email NOT LIKE '%@system.local'
      AND u.password_hash IS NULL
      AND u.password IS NULL
      AND u.pin_hash IS NULL
      AND NOT EXISTS (
          SELECT 1 FROM owner_onboarding_tokens t
          WHERE t.user_id = u.id
            AND t.consumed_at IS NULL
            AND t.expires_at > NOW()
      )
);
