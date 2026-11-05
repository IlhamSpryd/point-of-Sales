<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daftar seluruh relasi Tier 2/3 yang dikonversi dari single-column FK
     * menjadi composite tenant FK: (tenant_id, column) -> parent_table(tenant_id, id) ON DELETE RESTRICT.
     *
     * @var array<string, list<array{column: string, parent_table: string, old_fk: string, new_fk: string, old_delete_rule: string}>>
     */
    protected array $compositeRelations = [
        'accounting_periods' => [
            ['column' => 'closed_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_acc_periods_closed_by', 'new_fk' => 'fk_accounting_periods_closed_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'reopened_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_acc_periods_reopened_by', 'new_fk' => 'fk_accounting_periods_reopened_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'ap_ledger_entries' => [
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_aple_created_by', 'new_fk' => 'fk_ap_ledger_entries_created_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'ap_payments' => [
            ['column' => 'approved_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_appay_approved_by', 'new_fk' => 'fk_ap_payments_approved_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_appay_created_by', 'new_fk' => 'fk_ap_payments_created_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'posted_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_appay_posted_by', 'new_fk' => 'fk_ap_payments_posted_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'voided_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_appay_voided_by', 'new_fk' => 'fk_ap_payments_voided_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'ar_ledger_entries' => [
            ['column' => 'actor_user_id', 'parent_table' => 'users', 'old_fk' => 'arle_actor_user_fk', 'new_fk' => 'fk_ar_ledger_entries_actor_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'ar_payment_allocations' => [
            ['column' => 'allocated_by_user_id', 'parent_table' => 'users', 'old_fk' => 'arpa_allocator_fk', 'new_fk' => 'fk_ar_payment_allocations_allocated_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'reversed_by_user_id', 'parent_table' => 'users', 'old_fk' => 'arpa_reverser_fk', 'new_fk' => 'fk_ar_payment_allocations_reversed_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'attendance_breaks' => [
            ['column' => 'attendance_record_id', 'parent_table' => 'attendance_records', 'old_fk' => 'attendance_breaks_attendance_record_id_foreign', 'new_fk' => 'fk_attendance_breaks_attendance_record_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'attendance_records' => [
            ['column' => 'approval_id', 'parent_table' => 'approvals', 'old_fk' => 'attendance_records_approval_id_foreign', 'new_fk' => 'fk_attendance_records_approval_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'corrected_by_user_id', 'parent_table' => 'users', 'old_fk' => 'attendance_records_corrected_by_user_id_foreign', 'new_fk' => 'fk_attendance_records_corrected_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'device_id', 'parent_table' => 'devices', 'old_fk' => 'attendance_records_device_id_foreign', 'new_fk' => 'fk_attendance_records_device_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'attendance_records_employee_id_foreign', 'new_fk' => 'fk_attendance_records_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'attendance_records_store_id_foreign', 'new_fk' => 'fk_attendance_records_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'work_schedule_id', 'parent_table' => 'work_schedules', 'old_fk' => 'attendance_records_work_schedule_id_foreign', 'new_fk' => 'fk_attendance_records_work_schedule_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'bank_reconciliation_matches' => [
            ['column' => 'matched_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_brm_matched_by', 'new_fk' => 'fk_bank_reconciliation_ma_matched_by_user_id_030f04_tc', 'old_delete_rule' => 'RESTRICT'],
        ],
        'bank_reconciliation_sessions' => [
            ['column' => 'completed_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_brs_completed_by', 'new_fk' => 'fk_bank_reconciliation_se_completed_by_user_id_95ac82_tc', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'started_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_brs_started_by', 'new_fk' => 'fk_bank_reconciliation_se_started_by_user_id_5c41ce_tc', 'old_delete_rule' => 'RESTRICT'],
        ],
        'bank_statements' => [
            ['column' => 'imported_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_bs_imported_by', 'new_fk' => 'fk_bank_statements_imported_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'cash_bank_transactions' => [
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_cbt_created_by', 'new_fk' => 'fk_cash_bank_transactions_created_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'posted_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_cbt_posted_by', 'new_fk' => 'fk_cash_bank_transactions_posted_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'reversed_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_cbt_reversed_by', 'new_fk' => 'fk_cash_bank_transactions_reversed_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'chart_of_accounts' => [
            ['column' => 'created_by', 'parent_table' => 'users', 'old_fk' => 'fk_coa_created_by', 'new_fk' => 'fk_chart_of_accounts_created_by_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'updated_by', 'parent_table' => 'users', 'old_fk' => 'fk_coa_updated_by', 'new_fk' => 'fk_chart_of_accounts_updated_by_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'commission_records' => [
            ['column' => 'commission_rule_id', 'parent_table' => 'commission_rules', 'old_fk' => 'commission_records_commission_rule_id_foreign', 'new_fk' => 'fk_commission_records_commission_rule_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'commission_records_employee_id_foreign', 'new_fk' => 'fk_commission_records_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'order_id', 'parent_table' => 'orders', 'old_fk' => 'commission_records_order_id_foreign', 'new_fk' => 'fk_commission_records_order_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'order_item_id', 'parent_table' => 'order_items', 'old_fk' => 'commission_records_order_item_id_foreign', 'new_fk' => 'fk_commission_records_order_item_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'reversal_source_id', 'parent_table' => 'commission_records', 'old_fk' => 'commission_records_reversal_source_id_foreign', 'new_fk' => 'fk_commission_records_reversal_source_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'commission_records_store_id_foreign', 'new_fk' => 'fk_commission_records_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'commission_rules' => [
            ['column' => 'product_id', 'parent_table' => 'products', 'old_fk' => 'commission_rules_product_id_foreign', 'new_fk' => 'fk_commission_rules_product_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'commission_rules_store_id_foreign', 'new_fk' => 'fk_commission_rules_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'customer_credit_accounts' => [
            ['column' => 'customer_id', 'parent_table' => 'customers', 'old_fk' => 'customer_credit_accounts_customer_id_foreign', 'new_fk' => 'fk_customer_credit_accounts_customer_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'updated_by_user_id', 'parent_table' => 'users', 'old_fk' => 'customer_credit_accounts_updated_by_user_id_foreign', 'new_fk' => 'fk_customer_credit_accounts_updated_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'customer_credit_logs' => [
            ['column' => 'actor_user_id', 'parent_table' => 'users', 'old_fk' => 'customer_credit_logs_actor_user_id_foreign', 'new_fk' => 'fk_customer_credit_logs_actor_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'customer_id', 'parent_table' => 'customers', 'old_fk' => 'customer_credit_logs_customer_id_foreign', 'new_fk' => 'fk_customer_credit_logs_customer_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'customer_groups' => [
            ['column' => 'default_price_list_id', 'parent_table' => 'price_lists', 'old_fk' => 'cg_price_list_fk', 'new_fk' => 'fk_customer_groups_default_price_list_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'customer_invoices' => [
            ['column' => 'posted_by_user_id', 'parent_table' => 'users', 'old_fk' => 'cinv_posted_by_fk', 'new_fk' => 'fk_customer_invoices_posted_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'voided_by_user_id', 'parent_table' => 'users', 'old_fk' => 'cinv_voided_by_fk', 'new_fk' => 'fk_customer_invoices_voided_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'customer_merge_logs' => [
            ['column' => 'merged_by_user_id', 'parent_table' => 'users', 'old_fk' => 'cml_merged_by_user_fk', 'new_fk' => 'fk_customer_merge_logs_merged_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'customers' => [
            ['column' => 'customer_group_id', 'parent_table' => 'customer_groups', 'old_fk' => 'customers_group_fk', 'new_fk' => 'fk_customers_customer_group_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'default_store_id', 'parent_table' => 'stores', 'old_fk' => 'customers_default_store_fk', 'new_fk' => 'fk_customers_default_store_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'merged_into_customer_id', 'parent_table' => 'customers', 'old_fk' => 'customers_merged_into_fk', 'new_fk' => 'fk_customers_merged_into_customer_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'price_list_id', 'parent_table' => 'price_lists', 'old_fk' => 'customers_price_list_fk', 'new_fk' => 'fk_customers_price_list_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'sales_rep_user_id', 'parent_table' => 'users', 'old_fk' => 'customers_sales_rep_fk', 'new_fk' => 'fk_customers_sales_rep_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'departments' => [
            ['column' => 'parent_id', 'parent_table' => 'departments', 'old_fk' => 'departments_parent_id_foreign', 'new_fk' => 'fk_departments_parent_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'employee_bank_accounts' => [
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'employee_bank_accounts_employee_id_foreign', 'new_fk' => 'fk_employee_bank_accounts_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'employee_bpjs_profiles' => [
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'employee_bpjs_profiles_employee_id_foreign', 'new_fk' => 'fk_employee_bpjs_profiles_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'employee_branch_assignments' => [
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'employee_branch_assignments_employee_id_foreign', 'new_fk' => 'fk_employee_branch_assignments_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'employee_branch_assignments_store_id_foreign', 'new_fk' => 'fk_employee_branch_assignments_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'employee_compensations' => [
            ['column' => 'approval_id', 'parent_table' => 'approvals', 'old_fk' => 'employee_compensations_approval_id_foreign', 'new_fk' => 'fk_employee_compensations_approval_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'employee_compensations_employee_id_foreign', 'new_fk' => 'fk_employee_compensations_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'salary_component_id', 'parent_table' => 'salary_components', 'old_fk' => 'employee_compensations_salary_component_id_foreign', 'new_fk' => 'fk_employee_compensations_salary_component_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'employee_employment_histories' => [
            ['column' => 'actor_user_id', 'parent_table' => 'users', 'old_fk' => 'employee_employment_histories_actor_user_id_foreign', 'new_fk' => 'fk_employee_employment_histories_actor_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'employee_employment_histories_employee_id_foreign', 'new_fk' => 'fk_employee_employment_histories_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'from_department_id', 'parent_table' => 'departments', 'old_fk' => 'employee_employment_histories_from_department_id_foreign', 'new_fk' => 'fk_employee_employment_hi_from_department_id_030a28_tc', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'from_job_title_id', 'parent_table' => 'job_titles', 'old_fk' => 'employee_employment_histories_from_job_title_id_foreign', 'new_fk' => 'fk_employee_employment_hi_from_job_title_id_8c78e6_tc', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'to_department_id', 'parent_table' => 'departments', 'old_fk' => 'employee_employment_histories_to_department_id_foreign', 'new_fk' => 'fk_employee_employment_hi_to_department_id_5741cf_tc', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'to_job_title_id', 'parent_table' => 'job_titles', 'old_fk' => 'employee_employment_histories_to_job_title_id_foreign', 'new_fk' => 'fk_employee_employment_histories_to_job_title_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'employee_tax_profiles' => [
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'employee_tax_profiles_employee_id_foreign', 'new_fk' => 'fk_employee_tax_profiles_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'employees' => [
            ['column' => 'department_id', 'parent_table' => 'departments', 'old_fk' => 'employees_department_id_foreign', 'new_fk' => 'fk_employees_department_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'job_title_id', 'parent_table' => 'job_titles', 'old_fk' => 'employees_job_title_id_foreign', 'new_fk' => 'fk_employees_job_title_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'manager_id', 'parent_table' => 'employees', 'old_fk' => 'employees_manager_id_foreign', 'new_fk' => 'fk_employees_manager_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'user_id', 'parent_table' => 'users', 'old_fk' => 'employees_user_id_foreign', 'new_fk' => 'fk_employees_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'expense_approvals' => [
            ['column' => 'approver_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_eapp_approver', 'new_fk' => 'fk_expense_approvals_approver_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'expenses' => [
            ['column' => 'approved_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_exp_approved_by', 'new_fk' => 'fk_expenses_approved_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'submitted_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_exp_submitted_by', 'new_fk' => 'fk_expenses_submitted_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'gift_card_ledgers' => [
            ['column' => 'actor_user_id', 'parent_table' => 'users', 'old_fk' => 'gcl_actor_user_fk', 'new_fk' => 'fk_gift_card_ledgers_actor_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'goods_received_note_lines' => [
            ['column' => 'ingredient_stock_movement_id', 'parent_table' => 'ingredient_stock_movements', 'old_fk' => 'grnl_ingredient_movement_fk', 'new_fk' => 'fk_goods_received_note_li_ingredient_stock_movem_b3ae40_tc', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'stock_movement_id', 'parent_table' => 'stock_movements', 'old_fk' => 'grnl_stock_movement_fk', 'new_fk' => 'fk_goods_received_note_lines_stock_movement_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'job_titles' => [
            ['column' => 'department_id', 'parent_table' => 'departments', 'old_fk' => 'job_titles_department_id_foreign', 'new_fk' => 'fk_job_titles_department_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'journal_entries' => [
            ['column' => 'approved_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_je_approved_by', 'new_fk' => 'fk_journal_entries_approved_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_je_created_by', 'new_fk' => 'fk_journal_entries_created_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'posted_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_je_posted_by', 'new_fk' => 'fk_journal_entries_posted_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'leave_balances' => [
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'leave_balances_employee_id_foreign', 'new_fk' => 'fk_leave_balances_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'leave_policy_id', 'parent_table' => 'leave_policies', 'old_fk' => 'leave_balances_leave_policy_id_foreign', 'new_fk' => 'fk_leave_balances_leave_policy_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'leave_requests' => [
            ['column' => 'approval_id', 'parent_table' => 'approvals', 'old_fk' => 'leave_requests_approval_id_foreign', 'new_fk' => 'fk_leave_requests_approval_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'leave_requests_employee_id_foreign', 'new_fk' => 'fk_leave_requests_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'leave_policy_id', 'parent_table' => 'leave_policies', 'old_fk' => 'leave_requests_leave_policy_id_foreign', 'new_fk' => 'fk_leave_requests_leave_policy_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'leave_transactions' => [
            ['column' => 'actor_user_id', 'parent_table' => 'users', 'old_fk' => 'leave_transactions_actor_user_id_foreign', 'new_fk' => 'fk_leave_transactions_actor_user_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'leave_transactions_employee_id_foreign', 'new_fk' => 'fk_leave_transactions_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'leave_policy_id', 'parent_table' => 'leave_policies', 'old_fk' => 'leave_transactions_leave_policy_id_foreign', 'new_fk' => 'fk_leave_transactions_leave_policy_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'leave_request_id', 'parent_table' => 'leave_requests', 'old_fk' => 'leave_transactions_leave_request_id_foreign', 'new_fk' => 'fk_leave_transactions_leave_request_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'orders' => [
            ['column' => 'sales_order_id', 'parent_table' => 'sales_orders', 'old_fk' => 'orders_sales_order_fk', 'new_fk' => 'fk_orders_sales_order_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'overtime_records' => [
            ['column' => 'approval_id', 'parent_table' => 'approvals', 'old_fk' => 'overtime_records_approval_id_foreign', 'new_fk' => 'fk_overtime_records_approval_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'attendance_record_id', 'parent_table' => 'attendance_records', 'old_fk' => 'overtime_records_attendance_record_id_foreign', 'new_fk' => 'fk_overtime_records_attendance_record_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'overtime_records_employee_id_foreign', 'new_fk' => 'fk_overtime_records_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'overtime_records_store_id_foreign', 'new_fk' => 'fk_overtime_records_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'payroll_adjustments' => [
            ['column' => 'approval_id', 'parent_table' => 'approvals', 'old_fk' => 'payroll_adjustments_approval_id_foreign', 'new_fk' => 'fk_payroll_adjustments_approval_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'payroll_adjustments_created_by_user_id_foreign', 'new_fk' => 'fk_payroll_adjustments_created_by_user_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'payroll_adjustments_employee_id_foreign', 'new_fk' => 'fk_payroll_adjustments_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'payroll_batch_id', 'parent_table' => 'payroll_batches', 'old_fk' => 'payroll_adjustments_payroll_batch_id_foreign', 'new_fk' => 'fk_payroll_adjustments_payroll_batch_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'payroll_batches' => [
            ['column' => 'approved_by_user_id', 'parent_table' => 'users', 'old_fk' => 'payroll_batches_approved_by_user_id_foreign', 'new_fk' => 'fk_payroll_batches_approved_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'payroll_batches_created_by_user_id_foreign', 'new_fk' => 'fk_payroll_batches_created_by_user_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'payroll_period_id', 'parent_table' => 'payroll_periods', 'old_fk' => 'payroll_batches_payroll_period_id_foreign', 'new_fk' => 'fk_payroll_batches_payroll_period_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'payroll_items' => [
            ['column' => 'payroll_record_id', 'parent_table' => 'payroll_records', 'old_fk' => 'payroll_items_payroll_record_id_foreign', 'new_fk' => 'fk_payroll_items_payroll_record_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'payroll_records' => [
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'payroll_records_employee_id_foreign', 'new_fk' => 'fk_payroll_records_employee_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'payroll_batch_id', 'parent_table' => 'payroll_batches', 'old_fk' => 'payroll_records_payroll_batch_id_foreign', 'new_fk' => 'fk_payroll_records_payroll_batch_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'period_closing_checklists' => [
            ['column' => 'checked_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_pcc_checked_by', 'new_fk' => 'fk_period_closing_checklists_checked_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'period_closing_logs' => [
            ['column' => 'user_id', 'parent_table' => 'users', 'old_fk' => 'fk_pcl_user', 'new_fk' => 'fk_period_closing_logs_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'posting_rule_versions' => [
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_prv_created_by', 'new_fk' => 'fk_posting_rule_versions_created_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'posting_rules' => [
            ['column' => 'approved_by_user_id', 'parent_table' => 'users', 'old_fk' => 'fk_posting_rules_approved_by', 'new_fk' => 'fk_posting_rules_approved_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'price_lists' => [
            ['column' => 'approved_by_user_id', 'parent_table' => 'users', 'old_fk' => 'pl_approved_by_user_fk', 'new_fk' => 'fk_price_lists_approved_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'customer_group_id', 'parent_table' => 'customer_groups', 'old_fk' => 'pl_customer_group_fk', 'new_fk' => 'fk_price_lists_customer_group_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'promotion_rewards' => [
            ['column' => 'rule_id', 'parent_table' => 'promotion_rules', 'old_fk' => 'promotion_rewards_rule_id_foreign', 'new_fk' => 'fk_promotion_rewards_rule_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'promotion_rules' => [
            ['column' => 'campaign_id', 'parent_table' => 'promotion_campaigns', 'old_fk' => 'promotion_rules_campaign_id_foreign', 'new_fk' => 'fk_promotion_rules_campaign_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'promotion_store_mappings' => [
            ['column' => 'campaign_id', 'parent_table' => 'promotion_campaigns', 'old_fk' => 'promotion_store_mappings_campaign_id_foreign', 'new_fk' => 'fk_promotion_store_mappings_campaign_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'purchase_approval_logs' => [
            ['column' => 'approval_request_id', 'parent_table' => 'purchase_approval_requests', 'old_fk' => 'palog_req_fk', 'new_fk' => 'fk_purchase_approval_logs_approval_request_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'purchase_approval_requests' => [
            ['column' => 'rule_id', 'parent_table' => 'purchase_approval_rules', 'old_fk' => 'pareq_rule_fk', 'new_fk' => 'fk_purchase_approval_requests_rule_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'purchase_approval_rules' => [
            ['column' => 'approver_role_id', 'parent_table' => 'roles', 'old_fk' => 'par_role_fk', 'new_fk' => 'fk_purchase_approval_rules_approver_role_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'par_store_fk', 'new_fk' => 'fk_purchase_approval_rules_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'purchase_request_lines' => [
            ['column' => 'preferred_supplier_id', 'parent_table' => 'suppliers', 'old_fk' => 'pr_lines_preferred_supplier_fk', 'new_fk' => 'fk_purchase_request_lines_preferred_supplier_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'quotation_lines' => [
            ['column' => 'price_list_id', 'parent_table' => 'price_lists', 'old_fk' => 'ql_price_list_fk', 'new_fk' => 'fk_quotation_lines_price_list_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'tax_class_id', 'parent_table' => 'tax_classes', 'old_fk' => 'ql_tax_class_fk', 'new_fk' => 'fk_quotation_lines_tax_class_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'uom_id', 'parent_table' => 'units_of_measure', 'old_fk' => 'ql_uom_fk', 'new_fk' => 'fk_quotation_lines_uom_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'variant_id', 'parent_table' => 'product_variants', 'old_fk' => 'ql_variant_fk', 'new_fk' => 'fk_quotation_lines_variant_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'quotations' => [
            ['column' => 'approved_by_user_id', 'parent_table' => 'users', 'old_fk' => 'quotations_approved_by_fk', 'new_fk' => 'fk_quotations_approved_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'converted_sales_order_id', 'parent_table' => 'sales_orders', 'old_fk' => 'quotations_converted_so_fk', 'new_fk' => 'fk_quotations_converted_sales_order_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'quotations_created_by_fk', 'new_fk' => 'fk_quotations_created_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'customer_address_id', 'parent_table' => 'customer_addresses', 'old_fk' => 'quotations_customer_address_fk', 'new_fk' => 'fk_quotations_customer_address_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'parent_quotation_id', 'parent_table' => 'quotations', 'old_fk' => 'quotations_parent_fk', 'new_fk' => 'fk_quotations_parent_quotation_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'sales_rep_user_id', 'parent_table' => 'users', 'old_fk' => 'quotations_sales_rep_fk', 'new_fk' => 'fk_quotations_sales_rep_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'sales_order_lines' => [
            ['column' => 'price_list_id', 'parent_table' => 'price_lists', 'old_fk' => 'sol_price_list_fk', 'new_fk' => 'fk_sales_order_lines_price_list_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'uom_id', 'parent_table' => 'units_of_measure', 'old_fk' => 'sol_uom_fk', 'new_fk' => 'fk_sales_order_lines_uom_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'variant_id', 'parent_table' => 'product_variants', 'old_fk' => 'sol_variant_fk', 'new_fk' => 'fk_sales_order_lines_variant_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
        ],
        'sales_orders' => [
            ['column' => 'billing_address_id', 'parent_table' => 'customer_addresses', 'old_fk' => 'sales_orders_billing_address_fk', 'new_fk' => 'fk_sales_orders_billing_address_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'cancelled_by_user_id', 'parent_table' => 'users', 'old_fk' => 'sales_orders_cancelled_by_fk', 'new_fk' => 'fk_sales_orders_cancelled_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'confirmed_by_user_id', 'parent_table' => 'users', 'old_fk' => 'sales_orders_confirmed_by_fk', 'new_fk' => 'fk_sales_orders_confirmed_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'sales_orders_created_by_fk', 'new_fk' => 'fk_sales_orders_created_by_user_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'credit_override_approved_by', 'parent_table' => 'users', 'old_fk' => 'sales_orders_credit_override_fk', 'new_fk' => 'fk_sales_orders_credit_override_approved_by_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'quotation_id', 'parent_table' => 'quotations', 'old_fk' => 'sales_orders_quotation_fk', 'new_fk' => 'fk_sales_orders_quotation_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'shipping_address_id', 'parent_table' => 'customer_addresses', 'old_fk' => 'sales_orders_shipping_address_fk', 'new_fk' => 'fk_sales_orders_shipping_address_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'source_repeat_order_id', 'parent_table' => 'sales_orders', 'old_fk' => 'sales_orders_repeat_order_fk', 'new_fk' => 'fk_sales_orders_source_repeat_order_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'shift_templates' => [
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'shift_templates_store_id_foreign', 'new_fk' => 'fk_shift_templates_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'store_credit_ledgers' => [
            ['column' => 'actor_user_id', 'parent_table' => 'users', 'old_fk' => 'scl_actor_user_fk', 'new_fk' => 'fk_store_credit_ledgers_actor_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'supplier_returns' => [
            ['column' => 'goods_receipt_id', 'parent_table' => 'goods_receipts', 'old_fk' => 'sr_receipt_fk', 'new_fk' => 'fk_supplier_returns_goods_receipt_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'suppliers' => [
            ['column' => 'created_by_user_id', 'parent_table' => 'users', 'old_fk' => 'suppliers_created_by_user_fk', 'new_fk' => 'fk_suppliers_created_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
            ['column' => 'updated_by_user_id', 'parent_table' => 'users', 'old_fk' => 'suppliers_updated_by_user_fk', 'new_fk' => 'fk_suppliers_updated_by_user_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
        'user_invitation_stores' => [
            ['column' => 'user_invitation_id', 'parent_table' => 'user_invitations', 'old_fk' => 'fk_inv_store_invitation', 'new_fk' => 'fk_user_invitation_stores_user_invitation_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
        ],
        'work_schedules' => [
            ['column' => 'employee_id', 'parent_table' => 'employees', 'old_fk' => 'work_schedules_employee_id_foreign', 'new_fk' => 'fk_work_schedules_employee_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'shift_template_id', 'parent_table' => 'shift_templates', 'old_fk' => 'work_schedules_shift_template_id_foreign', 'new_fk' => 'fk_work_schedules_shift_template_id_tenant_comp', 'old_delete_rule' => 'RESTRICT'],
            ['column' => 'store_id', 'parent_table' => 'stores', 'old_fk' => 'work_schedules_store_id_foreign', 'new_fk' => 'fk_work_schedules_store_id_tenant_comp', 'old_delete_rule' => 'CASCADE'],
            ['column' => 'swapped_with_schedule_id', 'parent_table' => 'work_schedules', 'old_fk' => 'work_schedules_swapped_with_schedule_id_foreign', 'new_fk' => 'fk_work_schedules_swapped_with_schedule_id_tenant_comp', 'old_delete_rule' => 'SET NULL'],
        ],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        // 1. Pastikan seluruh tabel Tier 2/3 yang memiliki tenant_id NOT NULL dan id memiliki UNIQUE KEY (tenant_id, id)
        $this->ensureAllTenantCompositeUniqueKeys();

        // 2. Audit integritas pra-migrasi dan pasang composite FK per tabel anak
        $existingFksByTable = $this->getExistingForeignKeysByTable();

        foreach ($this->compositeRelations as $table => $relations) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $tableFks = $existingFksByTable[$table] ?? [];
            $dropClauses = [];
            $addClauses = [];

            foreach ($relations as $rel) {
                $col = $rel['column'];
                $parent = $rel['parent_table'];
                $oldFk = $rel['old_fk'];
                $newFk = $rel['new_fk'];

                // Pre-migration data integrity check: tolak jika ada orphan atau cross-tenant mismatch
                $mismatch = DB::selectOne(
                    "SELECT COUNT(*) AS cnt FROM `{$table}` c LEFT JOIN `{$parent}` p ON p.id = c.`{$col}` WHERE c.`{$col}` IS NOT NULL AND (p.id IS NULL OR p.tenant_id != c.tenant_id)"
                );
                if (((int) ($mismatch->cnt ?? 0)) > 0) {
                    throw new RuntimeException(
                        "Composite FK pre-migration audit failed on {$table}.{$col} -> {$parent}: found {$mismatch->cnt} orphan/cross-tenant row(s)."
                    );
                }

                if (isset($tableFks[$oldFk])) {
                    $dropClauses[] = "DROP FOREIGN KEY `{$oldFk}`";
                }

                if (! isset($tableFks[$newFk])) {
                    $addClauses[] = "ADD CONSTRAINT `{$newFk}` FOREIGN KEY (`tenant_id`, `{$col}`) REFERENCES `{$parent}` (`tenant_id`, `id`) ON DELETE RESTRICT ON UPDATE CASCADE";
                }
            }

            $allClauses = array_merge($dropClauses, $addClauses);
            if (! empty($allClauses)) {
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', $allClauses));
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $existingFksByTable = $this->getExistingForeignKeysByTable();
        $existingIndexesByTable = $this->getExistingSingleColumnIndexesByTable();

        foreach ($this->compositeRelations as $table => $relations) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $tableFks = $existingFksByTable[$table] ?? [];
            $tableIdxs = $existingIndexesByTable[$table] ?? [];
            $clauses = [];

            foreach ($relations as $rel) {
                $col = $rel['column'];
                $parent = $rel['parent_table'];
                $oldFk = $rel['old_fk'];
                $newFk = $rel['new_fk'];
                $delRule = $rel['old_delete_rule'];

                if (isset($tableFks[$newFk])) {
                    $clauses[] = "DROP FOREIGN KEY `{$newFk}`";
                }

                if (! isset($tableFks[$oldFk])) {
                    if (! isset($tableIdxs[$col])) {
                        $idxName = substr("idx_{$table}_{$col}", 0, 64);
                        $clauses[] = "ADD INDEX `{$idxName}` (`{$col}`)";
                        $tableIdxs[$col] = true;
                    }
                    $clauses[] = "ADD CONSTRAINT `{$oldFk}` FOREIGN KEY (`{$col}`) REFERENCES `{$parent}` (`id`) ON DELETE {$delRule}";
                }
            }

            if (! empty($clauses)) {
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', $clauses));
            }
        }
    }

    protected function ensureAllTenantCompositeUniqueKeys(): void
    {
        $tablesMissingUnique = DB::select("
            SELECT c1.TABLE_NAME AS table_name
            FROM information_schema.COLUMNS c1
            JOIN information_schema.COLUMNS c2
              ON c1.TABLE_SCHEMA = c2.TABLE_SCHEMA AND c1.TABLE_NAME = c2.TABLE_NAME
            WHERE c1.TABLE_SCHEMA = DATABASE()
              AND c1.COLUMN_NAME = 'tenant_id' AND c1.IS_NULLABLE = 'NO'
              AND c2.COLUMN_NAME = 'id'
              AND NOT EXISTS (
                SELECT 1 FROM information_schema.STATISTICS s
                WHERE s.TABLE_SCHEMA = DATABASE()
                  AND s.TABLE_NAME = c1.TABLE_NAME
                  AND s.NON_UNIQUE = 0
                GROUP BY s.INDEX_NAME
                HAVING GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) = 'tenant_id,id'
              )
            ORDER BY c1.TABLE_NAME
        ");

        foreach ($tablesMissingUnique as $row) {
            $table = (string) $row->table_name;
            $ukName = substr("{$table}_tenant_id_id_unique", 0, 64);
            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$ukName}` (`tenant_id`, `id`)");
        }
    }

    /**
     * @return array<string, array<string, bool>>
     */
    protected function getExistingForeignKeysByTable(): array
    {
        $rows = DB::select("
            SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS constraint_name
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->table_name][(string) $row->constraint_name] = true;
        }

        return $map;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    protected function getExistingSingleColumnIndexesByTable(): array
    {
        $rows = DB::select('
            SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND SEQ_IN_INDEX = 1
        ');

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->table_name][(string) $row->column_name] = true;
        }

        return $map;
    }
};
