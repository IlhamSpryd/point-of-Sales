-- 12_finance_gl_invariants.sql
-- Automated Database Invariant Verification for Modul 7 (Finance & General Ledger)
-- Invariants: FIN-M7-01 to FIN-M7-12

SELECT 'FAIL' AS result, anomaly_type, table_name, details
FROM (
    -- 1. FIN-M7-01: Balanced Journal Header (total_debit === total_credit > 0 for POSTED/REVERSED)
    SELECT
        'UNBALANCED_POSTED_JOURNAL' AS anomaly_type,
        'journal_entries' AS table_name,
        CONCAT('Journal #', id, ' [', journal_number, '] total_debit (', total_debit, ') != total_credit (', total_credit, ')') AS details
    FROM journal_entries
    WHERE status IN ('POSTED', 'REVERSED')
      AND (total_debit != total_credit OR total_debit <= 0 OR total_credit <= 0)

    UNION ALL

    -- 2. FIN-M7-01: Header totals must match SUM(journal_lines) exactly
    SELECT
        'HEADER_LINES_SUM_MISMATCH' AS anomaly_type,
        'journal_entries' AS table_name,
        CONCAT('Journal #', je.id, ' [', je.journal_number, '] Header (Dr: ', je.total_debit, ', Cr: ', je.total_credit, ') != Lines (Dr: ', COALESCE(jl.sum_dr, 0), ', Cr: ', COALESCE(jl.sum_cr, 0), ')') AS details
    FROM journal_entries je
    LEFT JOIN (
        SELECT journal_entry_id,
               SUM(debit_amount) AS sum_dr,
               SUM(credit_amount) AS sum_cr
        FROM journal_lines
        GROUP BY journal_entry_id
    ) jl ON jl.journal_entry_id = je.id
    WHERE je.status IN ('POSTED', 'REVERSED')
      AND (je.total_debit != COALESCE(jl.sum_dr, 0) OR je.total_credit != COALESCE(jl.sum_cr, 0))

    UNION ALL

    -- 3. FIN-M7-01: At least 2 lines required per POSTED/REVERSED journal
    SELECT
        'INSUFFICIENT_LINES_COUNT' AS anomaly_type,
        'journal_entries' AS table_name,
        CONCAT('Journal #', je.id, ' [', je.journal_number, '] has fewer than 2 lines (count: ', COALESCE(jl.cnt, 0), ')') AS details
    FROM journal_entries je
    LEFT JOIN (
        SELECT journal_entry_id, COUNT(*) AS cnt
        FROM journal_lines
        GROUP BY journal_entry_id
    ) jl ON jl.journal_entry_id = je.id
    WHERE je.status IN ('POSTED', 'REVERSED')
      AND COALESCE(jl.cnt, 0) < 2

    UNION ALL

    -- 4. FIN-M7-02: Single-Sided Line Exclusivity
    SELECT
        'NON_SINGLE_SIDED_LINE' AS anomaly_type,
        'journal_lines' AS table_name,
        CONCAT('Line #', id, ' in Journal #', journal_entry_id, ' invalid sides (Dr: ', debit_amount, ', Cr: ', credit_amount, ')') AS details
    FROM journal_lines
    WHERE (debit_amount > 0 AND credit_amount > 0)
       OR (debit_amount <= 0 AND credit_amount <= 0)

    UNION ALL

    -- 5. FIN-M7-10: Tenant Boundary Consistency between header and lines
    SELECT
        'TENANT_MISMATCH_HEADER_LINES' AS anomaly_type,
        'journal_lines' AS table_name,
        CONCAT('Line #', jl.id, ' tenant_id (', jl.tenant_id, ') != Journal #', je.id, ' tenant_id (', je.tenant_id, ')') AS details
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_entry_id
    WHERE jl.tenant_id != je.tenant_id

    UNION ALL

    -- 6. FIN-M7-10: Tenant Boundary Consistency between lines and chart_of_accounts
    SELECT
        'TENANT_MISMATCH_LINE_ACCOUNT' AS anomaly_type,
        'journal_lines' AS table_name,
        CONCAT('Line #', jl.id, ' tenant_id (', jl.tenant_id, ') != Account #', coa.id, ' tenant_id (', coa.tenant_id, ')') AS details
    FROM journal_lines jl
    JOIN chart_of_accounts coa ON coa.id = jl.account_id
    WHERE jl.tenant_id != coa.tenant_id

    UNION ALL

    -- 7. FIN-M7-10: Tenant Boundary Consistency between header and accounting_periods
    SELECT
        'TENANT_MISMATCH_HEADER_PERIOD' AS anomaly_type,
        'journal_entries' AS table_name,
        CONCAT('Journal #', je.id, ' tenant_id (', je.tenant_id, ') != Period #', ap.id, ' tenant_id (', ap.tenant_id, ')') AS details
    FROM journal_entries je
    JOIN accounting_periods ap ON ap.id = je.accounting_period_id
    WHERE je.tenant_id != ap.tenant_id

    UNION ALL

    -- 8. FIN-M7-07: Posted Line on Inactive or Non-Postable Summary Account
    SELECT
        'LINE_ON_INACTIVE_OR_SUMMARY_ACCOUNT' AS anomaly_type,
        'journal_lines' AS table_name,
        CONCAT('Line #', jl.id, ' references Account #', coa.id, ' [', coa.account_code, '] with is_active=', coa.is_active, ', is_postable=', coa.is_postable) AS details
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_entry_id
    JOIN chart_of_accounts coa ON coa.id = jl.account_id
    WHERE je.status IN ('POSTED', 'REVERSED')
      AND (coa.is_active = 0 OR coa.is_postable = 0)

    UNION ALL

    -- 9. FIN-M7-08: Closed-Period Enforcement Anomaly
    SELECT
        'POSTING_ON_LOCKED_PERIOD' AS anomaly_type,
        'journal_entries' AS table_name,
        CONCAT('Journal #', je.id, ' [', je.journal_number, '] posted to LOCKED period #', ap.id, ' [', ap.period_code, ']') AS details
    FROM journal_entries je
    JOIN accounting_periods ap ON ap.id = je.accounting_period_id
    WHERE je.status = 'POSTED'
      AND ap.status = 'LOCKED'

    UNION ALL

    -- 10. Trial Balance Equilibrium per Tenant (SUM(debits) === SUM(credits))
    SELECT
        'GLOBAL_TRIAL_BALANCE_OUT_OF_EQUILIBRIUM' AS anomaly_type,
        'journal_lines' AS table_name,
        CONCAT('Tenant #', tenant_id, ' Total Dr (', sum_dr, ') != Total Cr (', sum_cr, ')') AS details
    FROM (
        SELECT
            je.tenant_id,
            SUM(jl.debit_amount) AS sum_dr,
            SUM(jl.credit_amount) AS sum_cr
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.status IN ('POSTED', 'REVERSED')
        GROUP BY je.tenant_id
        HAVING SUM(jl.debit_amount) != SUM(jl.credit_amount)
    ) tb_imbalance
) anomalies;
