<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Exceptions\UnresolvableAccountMappingException;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\JournalEntryService;
use App\Modules\HR\Enums\PayrollBatchStatusEnum;
use App\Modules\HR\Models\PayrollBatch;
use App\Modules\HR\Models\PayrollRecord;
use DomainException;
use Illuminate\Support\Facades\DB;

class PayrollPostingService
{
    public function __construct(
        protected JournalEntryService $journalEntryService
    ) {}

    /**
     * Memposting jurnal akrual beban dan utang penggajian ke General Ledger (Modul 7).
     *
     * @throws DomainException
     */
    public function postAccrualJournal(PayrollBatch $batch, ?int $userId = null): JournalEntry
    {
        if ($batch->status !== PayrollBatchStatusEnum::FINALIZED && $batch->status !== PayrollBatchStatusEnum::PAID) {
            throw new DomainException("Batch penggajian [{$batch->batch_number}] harus berstatus FINALIZED sebelum jurnal akrual dapat diposting.");
        }

        // Cek jika sudah terhubung ke jurnal
        if ($batch->gl_accrual_journal_id) {
            /** @var JournalEntry|null $existing */
            $existing = JournalEntry::withoutTenantScope()
                ->where('tenant_id', $batch->tenant_id)
                ->where('id', $batch->gl_accrual_journal_id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $idempotencyKey = "PAYROLL-{$batch->tenant_id}-{$batch->id}-ACCRUAL";

        /** @var JournalEntry|null $existingByIdemp */
        $existingByIdemp = JournalEntry::withoutTenantScope()
            ->where('tenant_id', $batch->tenant_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingByIdemp) {
            $batch->gl_accrual_journal_id = $existingByIdemp->id;
            $batch->save();

            return $existingByIdemp;
        }

        return DB::transaction(function () use ($batch, $userId, $idempotencyKey) {
            // Agregasi dari payroll_records untuk akurasi sub-komponen
            $records = PayrollRecord::withoutTenantScope()
                ->where('tenant_id', $batch->tenant_id)
                ->where('payroll_batch_id', $batch->id)
                ->get();

            $totalSalaryExpense = '0.00';
            $totalOvertime = '0.00';
            $totalCommission = '0.00';
            $totalBpjsEmployer = '0.00';

            $totalNetPay = '0.00';
            $totalTaxPph21 = '0.00';
            $totalBpjsPayable = '0.00';
            $totalOtherDeductions = '0.00';

            foreach ($records as $record) {
                $baseComp = bcadd(
                    bcadd((string) $record->basic_salary_amount, (string) $record->fixed_allowances_amount, 2),
                    (string) $record->variable_allowances_amount,
                    2
                );
                $totalSalaryExpense = bcadd($totalSalaryExpense, $baseComp, 2);
                $totalOvertime = bcadd($totalOvertime, (string) $record->overtime_amount, 2);
                $totalCommission = bcadd($totalCommission, (string) $record->commissions_amount, 2);

                $bpjsEr = bcadd((string) $record->bpjs_ks_employer_amount, (string) $record->bpjs_tk_employer_amount, 2);
                $totalBpjsEmployer = bcadd($totalBpjsEmployer, $bpjsEr, 2);

                $totalNetPay = bcadd($totalNetPay, (string) $record->net_pay_amount, 2);
                $totalTaxPph21 = bcadd($totalTaxPph21, (string) $record->tax_pph21_amount, 2);

                $bpjsTotalRecord = bcadd(
                    bcadd($bpjsEr, (string) $record->bpjs_ks_employee_amount, 2),
                    (string) $record->bpjs_tk_employee_amount,
                    2
                );
                $totalBpjsPayable = bcadd($totalBpjsPayable, $bpjsTotalRecord, 2);
                $totalOtherDeductions = bcadd($totalOtherDeductions, (string) $record->other_deductions_amount, 2);
            }

            // Fallback ke total batch jika records kosong (misal batch 0 karyawan)
            if ($records->isEmpty()) {
                $totalNetPay = number_format((float) $batch->total_net_amount, 2, '.', '');
                $totalSalaryExpense = $totalNetPay;
            }

            // Resolve CoA
            $salaryAccount = $this->resolveAccount('SALARY_EXPENSE_DIRECT', $batch->tenant_id, ['EXPENSE_SALARY'], '5301');
            $overtimeAccount = $this->resolveAccount('OVERTIME_EXPENSE', $batch->tenant_id, ['SALARY_EXPENSE_DIRECT'], '5302');
            $commissionAccount = $this->resolveAccount('COMMISSION_EXPENSE', $batch->tenant_id, ['SALARY_EXPENSE_DIRECT'], '5303');
            $bpjsEmployerAccount = $this->resolveAccount('BPJS_EMPLOYER_EXPENSE', $batch->tenant_id, ['SALARY_EXPENSE_DIRECT'], '5304');

            $payrollPayableAccount = $this->resolveAccount('PAYROLL_PAYABLE', $batch->tenant_id, [], '2102');
            $taxPph21PayableAccount = $this->resolveAccount('TAX_PPH21_PAYABLE', $batch->tenant_id, [], '2103');
            $bpjsPayableAccount = $this->resolveAccount('BPJS_PAYABLE', $batch->tenant_id, [], '2106');
            $employeeReceivableAccount = $this->resolveAccount('EMPLOYEE_RECEIVABLE', $batch->tenant_id, [], '1135');

            /** @var array<JournalLineDto> $lines */
            $lines = [];

            // Debet: Beban Gaji
            if (bccomp($totalSalaryExpense, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $salaryAccount->id,
                    debitAmount: $totalSalaryExpense,
                    creditAmount: '0.00',
                    description: "Beban Gaji Staf Operasional - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            // Debet: Upah Lembur
            if (bccomp($totalOvertime, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $overtimeAccount->id,
                    debitAmount: $totalOvertime,
                    creditAmount: '0.00',
                    description: "Beban Upah Lembur Karyawan - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            // Debet: Komisi Penjualan
            if (bccomp($totalCommission, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $commissionAccount->id,
                    debitAmount: $totalCommission,
                    creditAmount: '0.00',
                    description: "Beban Komisi & Insentif - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            // Debet: Iuran BPJS Pemberi Kerja
            if (bccomp($totalBpjsEmployer, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $bpjsEmployerAccount->id,
                    debitAmount: $totalBpjsEmployer,
                    creditAmount: '0.00',
                    description: "Beban BPJS Ketenagakerjaan & Kesehatan ER - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            // Kredit: Utang Gaji (Net Pay)
            if (bccomp($totalNetPay, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $payrollPayableAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $totalNetPay,
                    description: "Utang Gaji Karyawan - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            // Kredit: Utang PPh 21
            if (bccomp($totalTaxPph21, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $taxPph21PayableAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $totalTaxPph21,
                    description: "Utang PPh 21 Karyawan - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            // Kredit: Utang BPJS (Pemberi Kerja + Pekerja)
            if (bccomp($totalBpjsPayable, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $bpjsPayableAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $totalBpjsPayable,
                    description: "Utang Iuran BPJS TK & Kes - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            // Kredit: Piutang Karyawan / Potongan Lainnya
            if (bccomp($totalOtherDeductions, '0.00', 2) > 0) {
                $lines[] = new JournalLineDto(
                    accountId: $employeeReceivableAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $totalOtherDeductions,
                    description: "Pemotongan Piutang Karyawan / Kasbon - Batch {$batch->batch_number}",
                    storeId: null
                );
            }

            /** @var User|null $actor */
            $actor = $userId ? User::find($userId) : null;

            $journal = $this->journalEntryService->createAndPostJournal(
                tenantId: $batch->tenant_id,
                storeId: null,
                postingDate: $batch->period_end_date,
                idempotencyKey: $idempotencyKey,
                lines: $lines,
                sourceModule: 'HR',
                sourceType: 'PAYROLL_ACCRUAL',
                sourceId: $batch->id,
                sourceNumber: $batch->batch_number,
                journalType: JournalTypeEnum::SYSTEM_AUTO,
                notes: "Akrual Beban Penggajian Batch {$batch->batch_number}",
                actor: $actor
            );

            $batch->gl_accrual_journal_id = $journal->id;
            $batch->save();

            return $journal;
        });
    }

    /**
     * Memposting jurnal pelunasan utang gaji kas/bank ke General Ledger (Modul 7).
     *
     * @throws DomainException
     */
    public function postPaymentJournal(PayrollBatch $batch, int $cashBankAccountId, ?int $userId = null): JournalEntry
    {
        if ($batch->status !== PayrollBatchStatusEnum::FINALIZED && $batch->status !== PayrollBatchStatusEnum::PAID) {
            throw new DomainException("Batch penggajian [{$batch->batch_number}] harus berstatus FINALIZED sebelum jurnal pembayaran dapat diposting.");
        }

        if ($batch->gl_payment_journal_id) {
            /** @var JournalEntry|null $existing */
            $existing = JournalEntry::withoutTenantScope()
                ->where('tenant_id', $batch->tenant_id)
                ->where('id', $batch->gl_payment_journal_id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $idempotencyKey = "PAYROLL-{$batch->tenant_id}-{$batch->id}-PAYMENT";

        /** @var JournalEntry|null $existingByIdemp */
        $existingByIdemp = JournalEntry::withoutTenantScope()
            ->where('tenant_id', $batch->tenant_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingByIdemp) {
            $batch->gl_payment_journal_id = $existingByIdemp->id;
            $batch->status = PayrollBatchStatusEnum::PAID;
            $batch->paid_at = now();
            $batch->save();

            return $existingByIdemp;
        }

        return DB::transaction(function () use ($batch, $cashBankAccountId, $userId, $idempotencyKey) {
            $payrollPayableAccount = $this->resolveAccount('PAYROLL_PAYABLE', $batch->tenant_id, [], '2102');

            /** @var ChartOfAccount|null $cashBankAccount */
            $cashBankAccount = ChartOfAccount::withoutTenantScope()
                ->where('tenant_id', $batch->tenant_id)
                ->where('id', $cashBankAccountId)
                ->where('is_active', true)
                ->first();

            if (! $cashBankAccount) {
                throw new DomainException("Akun Kas/Bank [ID: {$cashBankAccountId}] tidak valid atau tidak aktif.");
            }

            $totalNetAmount = number_format((float) $batch->total_net_amount, 2, '.', '');

            if (bccomp($totalNetAmount, '0.00', 2) <= 0) {
                throw new DomainException('Total nilai bersih penggajian harus lebih besar dari 0.00 untuk pelunasan.');
            }

            $lines = [
                // Debet: Eliminasi Utang Gaji
                new JournalLineDto(
                    accountId: $payrollPayableAccount->id,
                    debitAmount: $totalNetAmount,
                    creditAmount: '0.00',
                    description: "Pelunasan Utang Gaji Batch {$batch->batch_number}",
                    storeId: null
                ),
                // Kredit: Pengeluaran Kas / Bank
                new JournalLineDto(
                    accountId: $cashBankAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $totalNetAmount,
                    description: "Pengeluaran Kas/Bank Penggajian Batch {$batch->batch_number}",
                    storeId: null
                ),
            ];

            /** @var User|null $actor */
            $actor = $userId ? User::find($userId) : null;

            $journal = $this->journalEntryService->createAndPostJournal(
                tenantId: $batch->tenant_id,
                storeId: null,
                postingDate: now()->toDateString(),
                idempotencyKey: $idempotencyKey,
                lines: $lines,
                sourceModule: 'HR',
                sourceType: 'PAYROLL_PAYMENT',
                sourceId: $batch->id,
                sourceNumber: $batch->batch_number,
                journalType: JournalTypeEnum::SYSTEM_AUTO,
                notes: "Pelunasan Gaji Karyawan Batch {$batch->batch_number}",
                actor: $actor
            );

            $batch->gl_payment_journal_id = $journal->id;
            $batch->status = PayrollBatchStatusEnum::PAID;
            $batch->paid_at = now();
            $batch->save();

            return $journal;
        });
    }

    /**
     * Resolve ChartOfAccount instance by system_account_key with fallbacks.
     *
     * @param  array<string>  $fallbackKeys
     */
    protected function resolveAccount(string $primaryKey, int $tenantId, array $fallbackKeys = [], ?string $fallbackCode = null): ChartOfAccount
    {
        $keys = array_merge([$primaryKey], $fallbackKeys);

        foreach ($keys as $key) {
            $account = ChartOfAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('system_account_key', $key)
                ->where('is_active', true)
                ->first();

            if ($account) {
                return $account;
            }
        }

        if ($fallbackCode) {
            $account = ChartOfAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('account_code', $fallbackCode)
                ->where('is_active', true)
                ->first();

            if ($account) {
                return $account;
            }
        }

        throw UnresolvableAccountMappingException::forSystemKey($primaryKey);
    }
}
