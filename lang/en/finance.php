<?php

declare(strict_types=1);

return [
    'title' => 'Financial Management',
    'subtitle' => 'General ledger, chart of accounts, journal entries, and automated financial statements.',
    'coa' => [
        'title' => 'Chart of Accounts',
        'account_code' => 'Account Code',
        'account_name' => 'Account Name',
        'account_type' => 'Account Type',
        'normal_balance' => 'Normal Balance',
        'current_balance' => 'Current Balance',
    ],
    'journals' => [
        'title' => 'Journal Entries',
        'entry_number' => 'Entry Number',
        'entry_date' => 'Posting Date',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'balance' => 'Balance',
        'unbalanced' => 'Journal entry is out of balance. Debits must equal credits.',
    ],
    'reconciliation' => [
        'title' => 'Bank Reconciliation',
        'bank_statement' => 'Bank Statement Balance',
        'ledger_balance' => 'General Ledger Balance',
        'unreconciled' => 'Unreconciled Difference',
        'match' => 'Reconcile Selected',
    ],
    'periods' => [
        'title' => 'Accounting Periods',
        'period_name' => 'Period Name',
        'start_date' => 'Start Date',
        'end_date' => 'End Date',
        'status' => 'Status',
        'close_period' => 'Close Accounting Period',
    ],
];
