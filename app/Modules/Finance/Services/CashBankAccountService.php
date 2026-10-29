<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Enums\CashBankAccountTypeEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\ChartOfAccount;
use DomainException;
use Illuminate\Support\Facades\DB;

class CashBankAccountService
{
    /**
     * Mendaftarkan akun master Kas / Bank baru dengan pemetaan 1:1 ke Chart of Account.
     *
     * @param  array<string, mixed>  $data
     */
    public function createAccount(array $data, User $actor): CashBankAccount
    {
        $tenantId = (int) ($data['tenant_id'] ?? $actor->tenant_id);

        // 1. Validasi Chart of Account
        $glAccountId = (int) ($data['gl_account_id'] ?? 0);
        $glAccount = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->find($glAccountId);

        if (! $glAccount) {
            throw new DomainException("FIN-M7-07: Akun GL #{$glAccountId} tidak ditemukan untuk tenant #{$tenantId}.");
        }

        if (! $glAccount->is_active) {
            throw new DomainException("FIN-M7-07: Akun GL '{$glAccount->account_code} - {$glAccount->account_name}' tidak aktif.");
        }

        // 2. Cek Invarian 1:1 CBA ke CoA (uq_cba_gl_account)
        $existingGlMapping = CashBankAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('gl_account_id', $glAccountId)
            ->exists();

        if ($existingGlMapping) {
            throw new DomainException("FIN-M7-06: Akun GL '{$glAccount->account_code}' sudah dipetakan ke akun kas/bank lain. Pemetaan akun kas/bank ke GL wajib 1:1.");
        }

        // 3. Cek Unik Kode Akun
        $accountCode = (string) ($data['account_code'] ?? '');
        if ($accountCode === '') {
            throw new DomainException('Kode akun kas/bank wajib diisi.');
        }

        $codeExists = CashBankAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_code', $accountCode)
            ->exists();

        if ($codeExists) {
            throw new DomainException("Kode akun kas/bank '{$accountCode}' sudah digunakan.");
        }

        // 4. Validasi Tipe Akun Kas & Bank
        $accountType = $data['account_type'];
        if (! ($accountType instanceof CashBankAccountTypeEnum)) {
            $accountType = CashBankAccountTypeEnum::tryFrom((string) $accountType)
                ?? throw new DomainException("Tipe akun kas/bank '{$accountType}' tidak valid.");
        }

        $openingBalance = bcadd((string) ($data['opening_balance'] ?? '0.00'), '0', 2);
        $currentBalance = $openingBalance;

        return CashBankAccount::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'store_id' => ! empty($data['store_id']) ? (int) $data['store_id'] : null,
            'account_code' => $accountCode,
            'account_name' => (string) ($data['account_name'] ?? $glAccount->account_name),
            'account_type' => $accountType,
            'gl_account_id' => $glAccount->id,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'bank_account_holder' => $data['bank_account_holder'] ?? null,
            'currency_code' => $data['currency_code'] ?? 'IDR',
            'opening_balance' => $openingBalance,
            'current_balance' => $currentBalance,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * Memperbarui informasi akun Kas / Bank.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(CashBankAccount $account, array $data, User $actor): CashBankAccount
    {
        if ($actor->tenant_id !== $account->tenant_id) {
            throw new DomainException('Tidak memiliki akses ke akun kas/bank tenant lain.');
        }

        $fillable = [
            'account_name',
            'store_id',
            'bank_name',
            'bank_account_number',
            'bank_account_holder',
            'is_active',
        ];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $account->{$field} = $data[$field];
            }
        }

        $account->save();

        return $account;
    }

    /**
     * Membaca saldo buku pembantu Kas / Bank saat ini.
     */
    public function getAccountBalance(CashBankAccount $account): string
    {
        return (string) $account->fresh()->current_balance;
    }

    /**
     * Memvalidasi bahwa saldo akun kas/bank mencukupi untuk penarikan / transfer.
     */
    public function assertSufficientBalance(CashBankAccount $account, string $amount): void
    {
        $currentBalance = (string) $account->current_balance;
        $normAmount = bcadd($amount, '0', 2);

        if (bccomp($currentBalance, $normAmount, 2) < 0) {
            throw new DomainException("Saldo akun kas/bank '{$account->account_name}' ({$currentBalance}) tidak mencukupi untuk pengeluaran sebesar {$normAmount}.");
        }
    }

    /**
     * Rekonsiliasi saldo buku pembantu CashBankAccount terhadap Buku Besar GL.
     *
     * @return array{account_id: int, account_code: string, gl_account_id: int, subledger_balance: string, gl_balance: string, difference: string, is_reconciled: bool}
     */
    public function reconcileWithGl(CashBankAccount $account): array
    {
        $freshAccount = $account->fresh() ?? $account;

        $glLines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.tenant_id', $freshAccount->tenant_id)
            ->where('journal_lines.account_id', $freshAccount->gl_account_id)
            ->where('journal_entries.status', JournalStatusEnum::POSTED->value)
            ->selectRaw('COALESCE(SUM(debit_amount), 0) as total_debit, COALESCE(SUM(credit_amount), 0) as total_credit')
            ->first();

        $glBalance = bcsub((string) ($glLines->total_debit ?? '0.00'), (string) ($glLines->total_credit ?? '0.00'), 2);
        $subledgerBalance = (string) $freshAccount->current_balance;
        $diff = bcsub($subledgerBalance, $glBalance, 2);

        return [
            'account_id' => $freshAccount->id,
            'account_code' => $freshAccount->account_code,
            'gl_account_id' => $freshAccount->gl_account_id,
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $diff,
            'is_reconciled' => bccomp($diff, '0.00', 2) === 0,
        ];
    }
}
