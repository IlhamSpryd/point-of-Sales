-- 13_hr_payroll_invariants.sql
-- Automated Database Invariant Verification for Modul 9 (HR, Workforce Management & Payroll)
-- Invariants: HR-M9-01 to HR-M9-08

SELECT 'FAIL' AS result, anomaly_type, table_name, details
FROM (
    -- 1. HR-M9-01: Batch Total Gross equals sum of records for CALCULATED/FINALIZED/PAID
    SELECT
        'BATCH_GROSS_MISMATCH' AS anomaly_type,
        'payroll_batches' AS table_name,
        CONCAT('Batch #', pb.id, ' [', pb.batch_number, '] Header Gross (', pb.total_gross_amount, ') != Records Gross (', COALESCE(pr.sum_gross, 0), ')') AS details
    FROM payroll_batches pb
    LEFT JOIN (
        SELECT payroll_batch_id,
               SUM(gross_earnings_amount) AS sum_gross
        FROM payroll_records
        GROUP BY payroll_batch_id
    ) pr ON pr.payroll_batch_id = pb.id
    WHERE pb.status IN ('CALCULATED', 'FINALIZED', 'PAID')
      AND pb.total_employees > 0
      AND pb.total_gross_amount != COALESCE(pr.sum_gross, 0)

    UNION ALL

    -- 2. HR-M9-01: Batch Total Net equals sum of records for CALCULATED/FINALIZED/PAID
    SELECT
        'BATCH_NET_MISMATCH' AS anomaly_type,
        'payroll_batches' AS table_name,
        CONCAT('Batch #', pb.id, ' [', pb.batch_number, '] Header Net (', pb.total_net_amount, ') != Records Net (', COALESCE(pr.sum_net, 0), ')') AS details
    FROM payroll_batches pb
    LEFT JOIN (
        SELECT payroll_batch_id,
               SUM(net_pay_amount) AS sum_net
        FROM payroll_records
        GROUP BY payroll_batch_id
    ) pr ON pr.payroll_batch_id = pb.id
    WHERE pb.status IN ('CALCULATED', 'FINALIZED', 'PAID')
      AND pb.total_employees > 0
      AND pb.total_net_amount != COALESCE(pr.sum_net, 0)

    UNION ALL

    -- 3. HR-M9-02: Individual Record Arithmetic Consistency (Gross - Tax - BPJS EE - Other = Net)
    SELECT
        'RECORD_NET_ARITHMETIC_MISMATCH' AS anomaly_type,
        'payroll_records' AS table_name,
        CONCAT('Record #', id, ' [Emp: ', employee_number_snapshot, '] Net (', net_pay_amount, ') != Computed Net (', (gross_earnings_amount - tax_pph21_amount - bpjs_ks_employee_amount - bpjs_tk_employee_amount - other_deductions_amount), ')') AS details
    FROM payroll_records
    WHERE net_pay_amount != (gross_earnings_amount - tax_pph21_amount - bpjs_ks_employee_amount - bpjs_tk_employee_amount - other_deductions_amount)

    UNION ALL

    -- 4. HR-M9-03: Leave Balance Remaining Days Consistency
    SELECT
        'LEAVE_BALANCE_MISMATCH' AS anomaly_type,
        'leave_balances' AS table_name,
        CONCAT('Balance #', id, ' remaining_days (', remaining_days, ') != allocated_days (', allocated_days, ') - used_days (', used_days, ') - expired_days (', expired_days, ')') AS details
    FROM leave_balances
    WHERE remaining_days != (allocated_days - used_days - expired_days)

    UNION ALL

    -- 5. HR-M9-04: Non-negative monetary invariants on payroll records
    SELECT
        'NEGATIVE_PAYROLL_AMOUNT' AS anomaly_type,
        'payroll_records' AS table_name,
        CONCAT('Record #', id, ' has negative amount: basic=', basic_salary_amount, ', gross=', gross_earnings_amount, ', net=', net_pay_amount) AS details
    FROM payroll_records
    WHERE basic_salary_amount < 0
       OR gross_earnings_amount < 0
       OR net_pay_amount < 0
       OR tax_pph21_amount < 0

    UNION ALL

    -- 6. HR-M9-05: Finalized Batch must have finalized_at set
    SELECT
        'FINALIZED_BATCH_WITHOUT_TIMESTAMP' AS anomaly_type,
        'payroll_batches' AS table_name,
        CONCAT('Batch #', id, ' [', batch_number, '] is FINALIZED but finalized_at is NULL') AS details
    FROM payroll_batches
    WHERE status IN ('FINALIZED', 'PAID')
      AND finalized_at IS NULL

    UNION ALL

    -- 7. HR-M9-06: Paid Batch must have paid_at set
    SELECT
        'PAID_BATCH_WITHOUT_TIMESTAMP' AS anomaly_type,
        'payroll_batches' AS table_name,
        CONCAT('Batch #', id, ' [', batch_number, '] is PAID but paid_at is NULL') AS details
    FROM payroll_batches
    WHERE status = 'PAID'
      AND paid_at IS NULL

    UNION ALL

    -- 8. HR-M9-07: Duplicate Employee Numbers per Tenant
    SELECT
        'DUPLICATE_EMPLOYEE_NUMBER' AS anomaly_type,
        'employees' AS table_name,
        CONCAT('Tenant #', tenant_id, ' has duplicate employee_number: ', employee_number, ' (count: ', COUNT(*), ')') AS details
    FROM employees
    GROUP BY tenant_id, employee_number
    HAVING COUNT(*) > 1

    UNION ALL

    -- 9. HR-M9-08: Overlapping Active Work Schedules for Same Employee on Same Date
    SELECT
        'OVERLAPPING_WORK_SCHEDULE' AS anomaly_type,
        'work_schedules' AS table_name,
        CONCAT('Employee #', employee_id, ' has multiple schedules on ', schedule_date, ' (count: ', COUNT(*), ')') AS details
    FROM work_schedules
    WHERE status != 'CANCELLED'
    GROUP BY tenant_id, employee_id, schedule_date
    HAVING COUNT(*) > 1
) anomalies;
